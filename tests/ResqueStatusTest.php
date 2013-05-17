<?php

/**
 * Test class for ResqueStatus
 *
 * PHP versions 5
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @author        Wan Qi Chen <kami@kamisama.me>
 * @copyright     Copyright 2013, Wan Qi Chen <kami@kamisama.me>
 * @link          http://cakeresque.kamisama.me
 * @since         0.0.1
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 **/

/**
 * ResqueStatusTest class
 *
 */

class ResqueStatusTest extends PHPUnit_Framework_TestCase {

	public function setUp() {
		parent::setUp();

		$this->redis = new Redis();
		$this->redis->connect('127.0.0.1', '6379');
		$this->redis->select(6);

		$this->ResqueStatus = new ResqueStatus($this->redis);

		ResqueStatus::$workerKey = 'test_' . ResqueStatus::$workerKey;
		ResqueStatus::$schedulerWorkerKey = 'test_' . ResqueStatus::$schedulerWorkerKey;
		ResqueStatus::$pausedWorkerKey . 'test_' . ResqueStatus::$pausedWorkerKey;

		$this->workers = array();
		$this->workers[100] = new Worker('One:100:queue5', 5);
		$this->workers[101] = new Worker('Two:101:queue1', 10);
		$this->workers[102] = new Worker('Three:102:schedulerWorker', 145);
	}

	public function tearDown() {
		parent::tearDown();
		$this->redis->del(ResqueStatus::$workerKey);
		$this->redis->del(ResqueStatus::$schedulerWorkerKey);
		$this->redis->del(ResqueStatus::$pausedWorkerKey);
	}

/**
 * @covers ResqueStatus::addWorker
 */
	public function testAddWorker() {
		$workers = array(
			"0125" => array('name' => 'WorkerZero'),
			"6523" => array('name' => 'workerOne', 'debug' => true)
		);
		$this->redis->hSet(ResqueStatus::$workerKey, "0125", serialize($workers["0125"]));

		$res = $this->ResqueStatus->addWorker(6523, $workers["6523"]);

		$this->assertTrue($res);

		$this->assertEquals(2, $this->redis->hLen(ResqueStatus::$workerKey));
		$datas = $this->redis->hGetAll(ResqueStatus::$workerKey);

		$this->assertEquals($workers["0125"], unserialize($datas["0125"]));
		unset($workers[1]['debug']);
		$this->assertEquals($workers["6523"], unserialize($datas["6523"]));
	}

/**
 * @covers ResqueStatus::registerSchedulerWorker
 */
	public function testRegisterSchedulerWorker() {
		$res = $this->ResqueStatus->registerSchedulerWorker(100);

		$this->assertTrue($res);
		$this->assertEquals(100, $this->redis->get(ResqueStatus::$schedulerWorkerKey));
	}

/**
 * @covers ResqueStatus::isSchedulerWorker
 */
	public function testIsSchedulerWoker() {
		$this->redis->set(ResqueStatus::$schedulerWorkerKey, '102');
		$this->assertTrue($this->ResqueStatus->isSchedulerWorker($this->workers[102]));
	}

/**
 * @covers ResqueStatus::isSchedulerWorker
 */
	public function testIsSchedulerWokerWhenFalse() {
		$this->assertFalse($this->ResqueStatus->isSchedulerWorker($this->workers[100]));
	}

/**
 * @covers ResqueStatus::isRunningSchedulerWorker
 */
	public function testIsRunningSchedulerWorker() {
		$this->redis->set(ResqueStatus::$schedulerWorkerKey, '100');
		$this->redis->hSet(ResqueStatus::$workerKey, 100, '');
		$this->assertTrue($this->ResqueStatus->isRunningSchedulerWorker());
	}

/**
 * @covers ResqueStatus::isRunningSchedulerWorker
 */
	public function testIsRunningSchedulerWorkerWhenItIsNotRunning() {
		$this->assertFalse($this->ResqueStatus->isRunningSchedulerWorker());
	}

/**
 * @covers ResqueStatus::isRunningSchedulerWorker
 */
	public function testIsRunningSchedulerWorkerCleanUpOldWorker() {
		$this->redis->set(ResqueStatus::$schedulerWorkerKey, '102');
		$this->redis->hSet(ResqueStatus::$workerKey, 100, '');
		$this->redis->hSet(ResqueStatus::$workerKey, 101, '');

		$status = $this->getMock('ResqueStatus', array('unregisterSchedulerWorker'), array($this->redis));

		$status->expects($this->once())->method('unregisterSchedulerWorker');
		$this->assertFalse($status->isRunningSchedulerWorker());
	}

/**
 * @covers ResqueStatus::unregisterSchedulerWorker
 */
	public function testUnregisterSchedulerWorker() {
		$worker = 'schedulerWorker';
		$this->redis->set(ResqueStatus::$schedulerWorkerKey, $worker);

		$this->assertTrue($this->ResqueStatus->unregisterSchedulerWorker());
		$this->assertFalse($this->redis->exists(ResqueStatus::$schedulerWorkerKey));
	}

/**
 * @covers ResqueStatus::getWorkers
 */
	public function testGetWorkers() {
		foreach ($this->workers as $pid => $worker) {
			$this->redis->hSet(ResqueStatus::$workerKey, $pid, serialize($worker));
		}

		$this->assertEquals($this->workers, $this->ResqueStatus->getWorkers());
	}

/**
 * @covers ResqueStatus::setPausedWorker
 */
	public function testSetPausedWorker() {
		$worker = 'workerName';
		$this->ResqueStatus->setPausedWorker($worker);

		$this->assertEquals(1, $this->redis->sCard(ResqueStatus::$pausedWorkerKey));
		$this->assertContains($worker, $this->redis->sMembers(ResqueStatus::$pausedWorkerKey));
	}

/**
 * @covers ResqueStatus::setPausedWorker
 */
	public function testSetActiveWorker() {
		$workers = array('workerOne', 'workerTwo');

		$this->redis->sAdd(ResqueStatus::$pausedWorkerKey, $workers[0]);
		$this->redis->sAdd(ResqueStatus::$pausedWorkerKey, $workers[1]);

		$this->ResqueStatus->setPausedWorker($workers[0], false);

		$pausedWorkers = $this->redis->sMembers(ResqueStatus::$pausedWorkerKey);
		$this->assertCount(1, $pausedWorkers);
		$this->assertEquals(array($workers[1]), $pausedWorkers);
	}

/**
 * @covers ResqueStatus::getPausedWorker
 */
	public function testGetPausedWorker() {
		$workers = array('workerOne', 'workerTwo');

		$this->redis->sAdd(ResqueStatus::$pausedWorkerKey, $workers[0]);
		$this->redis->sAdd(ResqueStatus::$pausedWorkerKey, $workers[1]);

		$pausedWorkers = $this->ResqueStatus->getPausedWorker();

		sort($pausedWorkers);
		sort($workers);

		$this->assertEquals($workers, $pausedWorkers);
	}

/**
 * Test that getPausedWorkers always return an array
 * @covers ResqueStatus::getPausedWorker
 */
	public function testGetPausedWorkerWhenThereIsNoPausedWorkers() {
		$this->assertEquals(array(), $this->ResqueStatus->getPausedWorker());
	}

/**
 * @covers ResqueStatus::removeWorker
 */
	public function testRemoveWorker() {
		foreach ($this->workers as $pid => $worker) {
			$this->redis->hSet(ResqueStatus::$workerKey, $pid, serialize($worker));
		}

		$this->ResqueStatus->removeWorker(100);

		$w = array_keys($this->workers);
		unset($w[100]);

		$ww = $this->redis->hKeys(ResqueStatus::$workerKey);

		$this->assertEquals(sort($w), sort($ww));
	}

/**
 * @covers ResqueStatus::clearWorkers
 */
	public function testClearWorkers() {
		$this->redis->set(ResqueStatus::$workerKey, 'one');
		$this->redis->set(ResqueStatus::$pausedWorkerKey, 'two');

		$this->ResqueStatus->clearWorkers();

		$this->assertFalse($this->redis->exists(ResqueStatus::$workerKey));
		$this->assertFalse($this->redis->exists(ResqueStatus::$pausedWorkerKey));
	}

}

class Worker {

	public $name;

	public $interval;

	public function __construct($name, $interval) {
		$this->name = $name;
		$this->interval = $interval;
	}

	public function __toString() {
		return $this->name;
	}
}
