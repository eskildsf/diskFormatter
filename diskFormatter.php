#!/usr/bin/php -q
<?php
// Copyright Eskild Schroll-Fleischer 2012
// Version 1.0.0
// eskild.sf@coderer.com


// [-standalone]	Use memory attached to current
//					process as database.
// [-memcache]		Use memcached database.
// [-flatfile]		Use flatfile database.
// [-s]				Run as slave to a master process.
// [-mac]			Run with Mac driver.
// [-ubuntu]		Run with ubuntu driver.
#// [-ask]			Ask before formatting.
define('s',chr(10));
define('sPause', 2.5);
define('sFile', './db.txt');
define('dMaster', 1);
define('dSlave', 2);
define('sMemcachedKey', 'knownDisks');
define('sMemcachedIP', '127.0.0.1');
define('sMemcachedPort', '11211');
define('sAskBeforeFormatting', FALSE);
define('sDirToCopyFrom', '/home/eskild/Dropbox/Programmering/USBFormatter/filesToCopy/');

//
// CLI Specific
//

exec('whoami', $output);
if ( $output[0] != 'root' ) {
	echo 'This script must be run as root.'.s;
	exit;
}

function getUserResponse() {
	echo ':';
	$handle = fopen('php://stdin','r');
	$userResponse = trim(fgets($handle));
	return $userResponse;
}

function presentHelp() {
	global $argv;
}

function isOptionSet($option) {
	global $argv;
	$return = FALSE;
	if ( is_string($option) ) {
		foreach ( $argv as $arg ) {
			if ( $arg == $option ) {
				$return = TRUE;
			}
		}
	}
	return $return;
}

//
// Objects
//

class Disks {
	public $disks = array();
	
	function addDisk($disk) {
		$this->disks[] = $disk;
	}
	
	function removeDiskById($id) {
		$newDisks = array();
		foreach ( $this->disks as $disk ) {
			if ( $disk->id != $id ) {
				$newDisks[] = $disk;
			}
		}
		$this->disks = $newDisks;
	}
	
	function diskIsKnown($id) {
		$return = FALSE;
		foreach ( $this->disks as $disk ) {
			if ( $disk->id == $id ) {
				$return = TRUE;
			}
		}
		return $return;
	}
}

class Disk {
	public $id;
	public $mountPoint;
	public $information;
	public $location;
	public $timestamp;
	
	function __construct($id, $mountPoint, $information) {
		$this->id = $id;
		$this->mountPoint = $mountPoint;
		$this->information = $information;
		
		$location = explode(' ', $this->mountPoint);
		$this->location = $location[0].'/';
	}
}

class Files {
	public $files;
	
	function __construct($path = FALSE) {
		$this->files = FALSE;
		if ( $path != FALSE && $handle = opendir($path)) {
			while (false !== ($entry = readdir($handle))) {
				if ($entry != "." && $entry != "..") {
					$this->files[] = new File($path.$entry);
				}
			}
		closedir($handle);
		}
	}
	
	function getStringOfFiles() {
		$return = ''; $s = '';
		if ( $this->files != FALSE ) {
			foreach ( $this->files as $file ) {
				$return .= $s.$file->name;
				$s = s;
			}
			$return .= s;
		}
		return $return;
	}
}

class File {
	public $absolutePath;
	public $name;
	public $md5hash;
	
	function __construct($absPath) {
		$this->absolutePath = $absPath;
		$this->md5hash = md5_file($this->absolutePath);
		$name = explode('/', $this->absolutePath);
		$this->name = end($name);
	}
}

class Statistics {
	public $treatedDisks;
	function __construct() {
		$this->treatedDisks = new Disks();
	}
	
	function addTreatedDisk($disk) {
		$disk->timestamp = mktime();
		$this->treatedDisks->addDIsk($disk);
	}
	
	function __destruct() {
		print_r($this->treatedDisks);
	}
}

//
// Drivers
//
if ( class_exists('Memcache') ) {
class memcacheDriver {
	public $conn;
	
	function __construct() {
		$this->conn = new Memcache();
		$this->conn->connect(sMemcachedIP, sMemcachedPort);
	}
	
	function __destruct() {
		$this->conn->close();
	}
	
	function clear() {
		$this->conn->flush();
	}
	
	function getKnownDisks() {
		return $this->conn->get(sMemcachedKey);
	}
	
	function setKnownDisks($disks) {
		if ( !$this->conn->replace(sMemcachedKey, $disks, 0, 0) ) {
			$this->conn->add(sMemcachedKey, $disks, 0, 0);
		}
	}
	
	function addKnownDisk($disk) {
		$knownDisks = $this->getKnownDisks();
		$knownDisks->addDisk($disk);
		$this->setKnownDisks($knownDisks);
	}
	
	function removeKnownDiskById($id) {
		$knownDisks = $this->getKnownDisks();
		$knownDisks->removeDiskById($id);
		$this->setKnownDisks($knownDisks);
	}
}
}

class flatFileDriver {
	public $file = sFile;
	
	function read() {
		/*
        $return = FALSE;
		$fileHandle = fopen($this->file, 'r');
		$return = fread($fileHandle, filesize($this->file));
		fclose($fileHandle);
		*/
		$return = file_get_contents($this->file);
		return unserialize($return);
	}
	
	function write($object) {
		$data = serialize($object);
		
		$fileHandle = fopen($this->file, 'w');
		flock($fileHandle, LOCK_EX);
		fwrite($fileHandle, $data);
		flock($fileHandle, LOCK_UN);
		fclose($fileHandle);
	}
	
	function clear() {
		file_put_contents($this->file, '');
	}
	
	function getKnownDisks() {
		return $this->read();
	}
	
	function setKnownDisks($disks) {
		$this->write($disks);
	}
	
	function addKnownDisk($disk) {
		$knownDisks = $this->getKnownDisks();
		$knownDisks->addDisk($disk);
		$this->setKnownDisks($knownDisks);
	}
	
	function removeKnownDiskById($id) {
		$knownDisks = $this->getKnownDisks();
		$knownDisks->removeDiskById($id);
		$this->setKnownDisks($knownDisks);
	}
}

class standaloneDriver {
	public $knownDisks;
	
	function __construct() {
		$this->knowDisks = new Disks();
	}
	
	function clear() {
		$this->knownDisks = NULL;
	}
	
	function getKnownDisks() {
		return $this->knownDisks;
	}
	
	function setKnownDisks($disks) {
		$this->knownDisks = $disks;
	}
	
	function addKnownDisk($disk) {
		$this->knownDisks->addDisk($disk);
	}
	
	function removeKnownDiskById($id) {
		$this->knownDisks->removeDiskById($id);
	}
}

if ( isOptionSet('-memcache') && class_exists('Memcache') ) {
	class Model extends memcacheDriver {}
} elseif ( isOptionSet('-flatfile') ) {
	class Model extends flatFileDriver {}
} elseif ( isOptionSet('-standalone') ) {
	class Model extends standaloneDriver {}
} else {
	class Model extends standaloneDriver {}
}

//
// Platform specific controller
//

class ubuntuDriver {
	function getConnectedDisks() {
		$disks = new Disks();
		exec('mount',$output);
		foreach ( $output as $line ) {
			if ( stristr($line, '/dev/') ) {
				$disk = getDiskFromMountOutput($line);
				$disks->addDisk($disk);
			}
		}
		return $disks;
	}
	
	function unmountDisk($disk) {
		$output = array();
		exec('umount -fv '.$disk->id, $output);
		echoOutput($output);
		if ( file_exists($disk->location) ) {
			rmdir($disk->location);
		}
	}
	
	function formatDisk($disk) {		
		$output = array();
		exec('mkfs.vfat -F 32 -I '.$disk->id, $output);
		echoOutput($output);
	}
	
	function mountDisk($disk) {
		if ( !file_exists($disk->location) ) {
			mkdir($disk->location);	
		}
		$output = array();		
		exec('mount '.$disk->id.' '.$disk->location, $output);
		echoOutput($output);
	}
}

class macDriver {
	function getConnectedDisks() {
		$disks = new Disks();
		exec('mount',$output);
		foreach ( $output as $line ) {
			if ( stristr($line, '/dev/') ) {
				$disk = getDiskFromMountOutput($line);
				$disks->addDisk($disk);
			}
		}
		return $disks;
	}
	
	function unmountDisk($disk) {		
		$output = array();
		exec('umount -fv '.$disk->id, $output);
		echoOutput($output);
	}
	
	function formatDisk($disk) {	
		$output = array();
		exec('diskutil reformat '.$disk->id, $output);
		echoOutput($output);
	}
	
	function mountDisk($disk) {
		if ( !file_exists($disk->location) ) {
			mkdir($disk->location);	
		}
		$output = array();		
		exec('mount '.$disk->id.' '.$disk->location, $output);
		echoOutput($output);
	}
}

if ( isOptionSet('-mac') ) {
	class ControllerExtender extends macDriver {}
} elseif ( isOptionSet('-ubuntu') ) {
	class ControllerExtender extends ubuntuDriver {}
} else {
	if ( stristr(PHP_OS, 'darwin') ) {
	class ControllerExtender extends macDriver {}
	} elseif ( stristr(PHP_OS, 'linux') ) {
	class ControllerExtender extends ubuntuDriver {}
	}
}

class Controller extends controllerExtender {
	public $m;
	public $f;
	//public $s;
	public $role = dMaster;
	
	function __construct() {
		$this->m = new Model();
		$this->f = new Files(sDirToCopyFrom);
		//$this->s = new Statistics();
	}
	
	function getInitialDisks() {
		$return = FALSE;
		if ( $this->role == dMaster ) {
			$return = $this->getConnectedDisks();
			$this->m->setKnownDisks($return);
		} elseif ( $this->role == dSlave ) {
			$return = $this->m->getKnownDisks();
		}
		return $return;
	}
	
	function copyFilesToDisk($disk) {
		if ( $this->f->files != FALSE ) {
			foreach ( $this->f->files as $file ) {
				copy($file->absolutePath, $disk->location.$file->name);
				if ( $file->md5hash != md5_file($disk->location.$file->name) ) {
					echo $file->name.' did not copy correctly.'.s;
					getUserResponse();
				}
			}
		}
	}
	
	function echoContentsOfDisk($disk) {
		$files = new Files($disk->location);
		
		echo 'Contents of disk:';
		echo s;
		echo $files->getStringOfFiles();
	}
	
	function detectNewDisks() {
		while ( true ) {
			$newDisks = new Disks();
			$disks = $this->getConnectedDisks();
			$knownDisks = $this->m->getKnownDisks();
			foreach ( $disks->disks as $disk ) {
				if ( !$knownDisks->diskIsKnown($disk->id) ) {
					$newDisks->addDisk($disk);
				}
			}
			if ( $newDisks->disks != array() ) {
				$currentDisk = $newDisks->disks[0];
				$this->m->addKnownDisk($currentDisk);
				$response = 'y';
				if ( sAskBeforeFormatting ) {
				echo 'Do you wish to treat '.$currentDisk->id.' on '.$currentDisk->mountPoint.'?'.s;
				$response = getUserResponse();
				}
				if ( !stristr($response,'n') ) {
					$this->treatDisk($currentDisk);
					break;
				}
			} else {
			presentDisks($newDisks);
			}
			sleep(sPause);
			
		}
	}
	
	function treatDisk($disk) {
		echo s;
		$this->unmountDisk($disk);
		$this->formatDisk($disk);
		
		if ( $this->f->files != FALSE ) {
		$this->mountDisk($disk);
		$this->copyFilesToDisk($disk);
		$this->echoContentsOfDisk($disk);
		$this->unmountDisk($disk);
		}
		
		echo $disk->id.' has been treated.'.s.s;
		$this->m->removeKnownDiskById($disk->id);
		$this->detectNewDisks();
	}
}

//
// Communication
//

function getDiskFromMountOutput($line) {
	$info = explode(' on ', $line);
	$id = $info[0];
	$info = explode(' (', $info[1]);
	$mountPoint = $info[0];
	$information = '('.$info[1];
	$disk = new Disk($id, $mountPoint, $information);
	return $disk;
}

function presentDisks($disks) {
	if ( $disks->disks == array() ) {
		echo 'There are no disks.'.s;
	} else {
		foreach ( $disks->disks as $disk ) {
			echo $disk->id.' on '.$disk->mountPoint.' with '.$disk->information.s;
		}
	}
}

function echoOutput($output) {
	foreach ( $output as $line ) {
		echo $line.s;
	}
}

//
// Run
//

$c = new Controller();
if ( isOptionSet('-s') ) {
	$c->role = dSlave;
}
$d = $c->getInitialDisks();
echo 'These are the devices that are currently connected.'.s.s;
presentDisks($d);
echo s;
echo 'They will not be touched.'.s;
echo 'The runloop will now start.'.s.s;
$c->detectNewDisks();
?>