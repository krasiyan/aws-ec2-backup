aws-ec2-backup
==============

Just a small AWS EC2 backup script built in PHP. It is meant to be run as a cron job from the command line. It accepts only 1 argument - the type of backup to make - snapshot or ami. The AWS config lies in backup.php. The script supports locking of MySQL databases, to protect the snapshots from corruption. 
