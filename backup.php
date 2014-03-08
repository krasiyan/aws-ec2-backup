<?php 
	/**
	 * @package Amazon AWS backup tool
	 * @category Script
	 * @author Krasiyan Nedelchev
	 */

	/*  Start config */
		define("API_KEY", ""); //aws api key
		define("API_SECRET", ""); //aws api secret key
		define("API_REGION", "us-east-1"); //aws region
		define("INSTANCE_ID", ""); //aws instance id
		define("VOLUME_ID", ""); //the id for the volume to backup
		define("SERVER_NAME", "Server"); //name of the service. for labeling use only
		define("BACKUP_NAME", SERVER_NAME . " auto generated backup"); //name to use as the backup name
		
		define("LOCK_TABLES", TRUE); //indicate wether the DB tables will be locked prior to the snapshot
		define("DB", ""); //database name
		define("DB_HOST", "localhost"); //database host
		define("DB_USER", "root"); //database user
		define("DB_PASS", ""); //database password

		define("NOTIFICATION_EMAIL", ""); //email to send notifications to
		define("LOGFILE", "aws_backup_log.txt"); //full path to the file for logging
		define("SNAPSHOT_MAX_AGE", 3); //maximum lifetime in days for snapshots
		define("SNAPSHOT_MIN_COUNT", 3); //minimum amount of snapshots to have a backups
		define("SNAPSHOT_WHITELIST", serialize(array( //a list of snapshots which wont be deleted

		)));	

		define("IMAGE_WHITELIST", serialize(array( //a list of AMI images which wont be deleted
			
		)));	

		define("DRY_RUN", FALSE);
	/*  End config */
	

	/* Start PHP settings */
		// error_reporting(-1); 
		// ini_set('display_errors', 1);
		set_time_limit(0);
	/* End PHP settings */

	/* Include the SDK using the Composer autoloader */
		require 'vendor/autoload.php';
		use Aws\Common\Aws;
		use Aws\Ec2\Ec2Client;
	/* End SDK */

	/* Initialize client */
		$ec2Client = \Aws\Ec2\Ec2Client::factory(array(
			'key'    => API_KEY,
			'secret' => API_SECRET,
			'curl.options' => array('CURLOPT_HTTP_VERSION'=>'CURL_HTTP_VERSION_1_0'),
			'region' => API_REGION,
		));
	/* End client */
	
	/*
	START BACKUP
	*/

	$backup_type = ArrGet($argv, 1);
	$possible_backup_types = array('snapshot', 'ami');
	if( ! $backup_type OR ! in_array($backup_type, $possible_backup_types))
	{
		$backup_type = 'snapshot';
	}

	if ( $backup_type == 'snapshot' )
	{
		if ( LOCK_TABLES )
		{
			$connection = dbConnect(); //connect to the database 
			if ( ! $connection OR ! toggleDatabase('lock')) // lock the tables
			{
				notify('Database connection/query error on LOCK TABLES', 'error');
				die();
			}
		}

		$volume = get_volume();
		if ( $volume )
		{
			log_act(
				'Starting snapshot backup for volume ' . 
				ArrGet($volume, 'VolumeId')
			);

			$snapshot_in_progresss = create_snapshot();
			if( $snapshot_in_progresss )
			{
				log_act(
					'The snapshot for volume ' . ArrGet($volume, 'VolumeId') .' is in progress! SnapshotId ' . 	$snapshot_in_progresss
				);
			
				$new_snapshot = wait_snapshot($snapshot_in_progresss);
				if( $new_snapshot )
				{
					log_act(
						'The snapshot for volume ' . ArrGet($volume, 'VolumeId') .' is completed! SnapshotId ' . $snapshot_in_progresss
					);
				
					$snapshots = get_snapshots();
					$deleted_snapshots  = array();

					if( sizeof($snapshots) >= SNAPSHOT_MIN_COUNT )
					{

						foreach( $snapshots as $snapshot)
						{
							if( check_expired(ArrGet($snapshot, 'StartTime'), 'snapshot') AND ! in_array(ArrGet($snapshot, 'SnapshotId'), unserialize(SNAPSHOT_WHITELIST)) )
							{ 
								$deleted_snapshots[] = ArrGet($snapshot, 'SnapshotId');
								log_act('Deleting snapshot '. ArrGet($snapshot, 'SnapshotId'));
								// delete_snapshot(ArrGet($snapshot, 'SnapshotId')); //commented out for security reasons
							}
						}
					}
					else 
					{
						log_act(
							'The volume ' . ArrGet($volume, 'VolumeId') .' doesn\'t have enough snapshots to delete the previous ones'
						);
					}
				
					log_act(
						'The volume ' . ArrGet($volume, 'VolumeId') .'\'s latest snapshot is ' . $snapshot_in_progresss  .
						( ! empty($deleted_snapshots) ? ' Deleted snapshots ' . implode(", ", $deleted_snapshots) : ' No previous snapshots were deleted' ),
						"notification"
					);


				} //end snapshot final creation
			} //end snapshot initial creation
		} //end volume check
		else 
		{
			log_act(
				'The volume ' . VOLUME_ID .' doesn\'t exist! Possibly a configuration error!', 
				'error'
			);
		}

		if ( LOCK_TABLES )
			{
			if ( ! toggleDatabase('unlock')) //unlock the tables
			{
				notify('Database connection/query error on UNLOCK TABLES', 'error');
				die();
			}
			$connection->close();
		}
		exit;
	}
	else if ( $backup_type == 'ami' )
	{
		$new_image = create_image();
		log_act(
			'Creating AMI backup image for instance ' . INSTANCE_ID . '. Image ID ' . $new_image
		);
		if ( $new_image)
		{
			$available_images = get_images();
			$deleted_images  = array();

			foreach( $available_images as $image)
			{
				if( 
					ArrGet($image, 'Name') == BACKUP_NAME AND 
					! in_array(ArrGet($image, 'ImageId'), unserialize(IMAGE_WHITELIST))
					// ArrGet($image, 'ImageId') != $new_image
				)
				{ 
					$deleted_images[] = ArrGet($image, 'ImageId');
					log_act('Deleting image '. ArrGet($image, 'ImageId'));
					// delete_image(ArrGet($image, 'ImageId')); //commented out for security reasons
				}
			}
		
			log_act(
				'The instance ' . INSTANCE_ID .'\'s latest AMI backup image is ' . $new_image  .
				( ! empty($deleted_images) ? ' Deleted images ' . implode(", ", $deleted_images) : ' No previous images were deleted' ),
				"notification"
			);
		}
	}
	
	/*
	END BACKUP
	 */


	/* Start helper methods */

	/**
	 * Get the selected volume information
	 */
	function get_volume()
	{
		global $ec2Client;
		try {
			$result = $ec2Client->describeVolumes(array(
				//'MaxResults' => 10,
				'VolumeIds' => array(
					VOLUME_ID
				)
			))->get('Volumes');

			$volume = ArrGet($result, 0, array());
			return $volume;
		}
		catch (Exception $e)
		{
			log_act('Error in get_volume() ' . $e->getMessage(), 'error');
			die();
		}
	}

	/**
	 * Get all snapshots for the volume
	 */
	function get_snapshots()
	{
		global $ec2Client;
		try {
			$result = $ec2Client->describeSnapshots(array(
				'OwnerIds' => array('self')
			));
			$all_snapshots = $result->get('Snapshots');
			$volume_snapshots = array();
			foreach ($all_snapshots as $snapshot) 
			{
				if( ArrGet($snapshot, 'VolumeId') == VOLUME_ID )
				{
					$volume_snapshots[] =  $snapshot;
				}
			}
			return $volume_snapshots;
		}
		catch (Exception $e)
		{
			log_act('Error in get_snapshots() ' . $e->getMessage(), 'error');
			die();
		}
	}

	/**
	 * Get all images for the instance
	 */
	function get_images()
	{
		global $ec2Client;
		try {
			$result = $ec2Client->describeImages(array(
				'DryRun' => DRY_RUN,
				'Owners' => array('self'),
				// 'ExecutableUsers' => array('self')
			));
			$all_images = $result->get('Images');
			// var_dump($result);
			return $all_images;
		}
		catch (Exception $e)
		{
			log_act('Error in get_images() ' . $e->getMessage(), 'error');
			die();
		}
	}
	/* Create a snapshot for the chosen volume */
	function create_snapshot()
	{
		global $ec2Client;
		try{
			$result = $ec2Client->createSnapshot(array(
				'DryRun' => DRY_RUN,
				'VolumeId' => VOLUME_ID,
				'Description' => BACKUP_NAME,
			));
			
			return $result->get('SnapshotId');
		}
		catch (Exception $e)
		{
			log_act('Error in create_snapshot() ' . $e->getMessage(), 'error');
			die();
		}
	}

	/* Create an image for the chosen instance */
	function create_image()
	{
		global $ec2Client;
		try{
			$result = $ec2Client->createImage(array(
				'DryRun' => DRY_RUN,
				'InstanceId' => INSTANCE_ID,
				'Name' => BACKUP_NAME,
				'Description' => BACKUP_NAME,
				'NoReboot' => FALSE,

			));
			
			return $result->get('ImageId');
		}
		catch (Exception $e)
		{
			log_act('Error in create_image() ' . $e->getMessage(), 'error');
			die();
		}
	}

	/* Delete a snapshot of the chosen volume */
	function delete_snapshot($snapshot_id = "")
	{
		global $ec2Client;
		try{
			$result = $ec2Client->deleteSnapshot(array(
				'DryRun' => DRY_RUN,
				'SnapshotId' => $snapshot_id
			));
			
			return $result;
		}
		catch (Exception $e)
		{
			log_act('Error in delete_snapshot() ' . $e->getMessage(), 'error');
			die();
		}
	}

	/* Delete an image of the chosen instance */
	function delete_image($image_id = "")
	{
		global $ec2Client;
		try{
			$result = $ec2Client->deregisterImage(array(
				'DryRun' => DRY_RUN,
				'ImageId' => $image_id
			));
			
			return $result;
		}
		catch (Exception $e)
		{
			log_act('Error in delete_image() ' . $e->getMessage(), 'error');
			die();
		}
	}

	/* wait for the snapshot creation to finish */
	function wait_snapshot($snapshot_id = "")
	{
		try{
			global $ec2Client;
			$result = $ec2Client->waitUntilSnapshotCompleted(array(
				'DryRun' => DRY_RUN,
				'OwnerIds' => array('self'),
				'SnapshotIds' => array($snapshot_id)
			));
			return TRUE;
		}
		catch (Exception $e)
		{
			log_act('Error in delete_snapshot() ' . $e->getMessage(), 'error');
			die();
		}
	}


	function check_expired($creation_date = "", $type='')
	{
		if( ! $type OR ! in_array($type, array('snapshot','ami')))
		{
			return FALSE;
		}

		if ( ! $creation_date )
		{
			return FALSE;
		}

		$max_age = ($type == 'snapshot' ? SNAPSHOT_MAX_AGE : ($type == 'ami' ? IMAGE_MAX_AGE : 99999));

		if ( strtotime($creation_date . "+" . $max_age . " day") < time() )
		{
			return TRUE;
		}
		return FALSE;
	}

	/* log action and send notifications if errors occur */
	function log_act($message = "", $level = "log")
	{
		$timestamp = "[" . date("Y-m-d H:i:s") . "]";
		$message = $timestamp . " " . $message . "\n";
		if( $level == "error")
		{
			$message = "ERROR: " . $message;
		}

		$log = fopen(LOGFILE, 'a');
		fwrite($log, $message);
		fclose($log);

		if( ($level == "notification" or $level == "error")  AND NOTIFICATION_EMAIL)
		{
			// mail(NOTIFICATION_EMAIL, "Amazon AWS Backup $level", $message);
		}

		print_r($message);
	}


	/* initialize the database connection object */
	function dbConnect(){
		$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB);
		if ($connection->connect_errno)
		{
			return FALSE;
		}
		return $connection;
	}

	/* database lock/unlock method */
	function toggleDatabase($action = "unlock")
	{
		global $connection;
		if ( ! $action OR ! $connection )
		{
			return FALSE;
		}
		
		$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB);
		if ($connection->connect_errno)
		{
			return FALSE;
		}

		if( $action == "lock" )
		{
			if ( $connection->query("FLUSH TABLES WITH READ LOCK;") === TRUE )
			{
				return TRUE;
			}
			return FALSE;
		}

		else if( $action == "unlock" )
		{
			if ( $connection->query("UNLOCK TABLES;") === TRUE )
			{
				return TRUE;
			}
			return FALSE;
		}
		return FALSE;
	}

	/* array getting helper */
	function ArrGet($array = array(), $item = 0 ,  $default = NULL)
	{
		return ( is_array($array) AND isset($array[$item]) ) ? $array[$item] : $default;
	}

	/* End helper methods */
?>