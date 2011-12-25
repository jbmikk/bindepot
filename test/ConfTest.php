<?php
require_once '../bindepot.php';
require_once 'PHPUnit/Framework.php';

class ConfTest extends PHPUnit_Framework_TestCase
{
	function setUp() {
		$this->bd = new Bindepot('images', './testconf.xml');
	}

	function tearDown() {
	}

	function testStoreAndRetrieve() {
		$this->bd->store('testfile', 'filecontents');
		$this->bd->retrieve('testfile', 'content');
	}
}

?>
