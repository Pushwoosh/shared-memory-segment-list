<?php

namespace Pushwoosh\SharedMemorySegmentList;

class Client {
	/**
	 * List max capacity. See SharedMemorySegmentList for more info
	 */
	private static $maxListCapacity = 4096;

	/** @var  bool */
	private $locked;

	/** @var  resource */
	private $semaphore;

	/**
	 * @var integer
	 * Shared memory list key
	 */
	private $listShmKey;

	/**
	 * @var int
	 * Semaphore shared key
	 */
	private $lockSemKey;

	public function __construct(int $lockSemKey, int $listShmKey) {
		$this->listShmKey = $listShmKey;
		$this->lockSemKey = $lockSemKey;
		$this->semaphore = \sem_get($this->lockSemKey, 1, 0664, 1);
		$this->checkAndInit();
	}

	/**
	 * Check if underlying shared memory segment list is initialized and initializes it if needed
	 * @throws \RuntimeException
	 */
	private function checkAndInit() {
		$this->lock();
		$list = new SegmentList($this->listShmKey);
		try {
			$list->read();
		}
		catch (SegmentListNotInitializedException $ex) {
			try {
				$list->init($this->getMaxListCapacity());
			}
			catch (\Exception $initEx) {
				throw new \RuntimeException("Cannot init shared memory list", 0, $initEx);
			}
		}
		finally {
			$this->unlock();
		}
	}

	/**
	 * Acquire exclusive lock
	 * @return bool
	 */
	private function lock(): bool {
		if ($this->locked) {
			return false;
		}
		$this->locked = true;
		$ret = \sem_acquire($this->semaphore, false);
		return $ret;
	}

	/**
	 * Release lock
	 * @return bool
	 */
	private function unlock(): bool {
		if (!$this->locked) {
			return false;
		}
		$ret = \sem_release($this->semaphore);
		if ($ret) {
			$this->locked = false;
		}
		return $ret;
	}

	/**
	 * Tries to allocate new segment.
	 * @throws \OutOfBoundsException
	 * @throws \RuntimeException
	 * @return int
	 */
	public function allocateSegment(): int {
		$this->lock();
		try {
			$list = new SegmentList($this->listShmKey);
			$id = $list->allocateSegment();
			return $id;
		}
		catch (\OutOfBoundsException $ex) {
			throw $ex;
		}
		catch (\Exception $ex) {
			throw new \RuntimeException("Cannot allocate segment", 0, $ex);
		}
		finally {
			$this->unlock();
		}
	}

	public function releaseSegment($id) {
		$this->lock();

		try {
			$list = new SegmentList($this->listShmKey);
			$list->releaseSegment($id);
		}
		catch (\OutOfBoundsException $oob) {

		}
		finally {
			$this->unlock();
		}
	}

	public function writeToSegment(int $id, string $data) {
		$this->lock();
		try {
			$list = new SegmentList($this->listShmKey);
			$list->writeToSegment($id, $data);
		}
		finally {
			$this->unlock();
		}
	}

	/**
	 * Returns array contain all segments data
	 */
	public function readAllSegments() {
		$this->lock();
		$ret = [];
		try {
			$list = new SegmentList($this->listShmKey);
			$segmentItems = $list->getItems();
			foreach (array_keys($segmentItems) as $id) {
				$ret[$id] = $list->readSegment($id);
			}
		}
		finally {
			$this->unlock();
		}
		return $ret;
	}

	public function destroy() {
		$this->lock();

		try {
			$list = new SegmentList($this->listShmKey);
			$list->destroy();
		}
		finally {
			$this->unlock();
		}
	}

	/**
	 * @return SegmentListItem[]
	 */
	public function getSegments() {
		$this->lock();

		try {
			$list = new SegmentList($this->listShmKey);
			return $list->getItems();
		}
		finally {
			$this->unlock();
		}
	}

	private function getMaxListCapacity(): int {
		return self::$maxListCapacity;
	}

	public function _debugMemoryDump() {
		$list = new SegmentList($this->listShmKey);
		$list->_debugMemoryDump();
	}
}
