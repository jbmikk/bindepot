<?php
require_once '../bindepot.php';
require_once 'PHPUnit/Framework.php';

class FileTest extends PHPUnit_Framework_TestCase
{
	function setUp() {
		$this->uploadFile = ['tmp_name'=> 'mock/path'];
	}

	function tearDown() {
	}

	function testUploadFile() {
		$file = new UploadFile($this->uploadFile);
		$this->assertEquals('mock/path', $file->path, 'Wrong uploaded file path');
	}

	function testDiskFile() {
		$file = new DiskFile('mock/path', 'id');
		$this->assertEquals('mock/path', $file->path, 'Wrong disk file path');
	}
}

?>
