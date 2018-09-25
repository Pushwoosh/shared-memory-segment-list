<?php

namespace Pushwoosh\SharedMemorySegmentList;

class SegmentListItemFactory {

	/**
	 * Creates empty list item
	 * @return SegmentListItem
	 */
	public static function newEmpty() {
		$self = new SegmentListItem(0, 0, 0, 0);
		return $self;
	}

	/**
	 * Decode Segment list item from binary
	 * @param string $binary
	 * @return SegmentListItem
	 * @throws \RuntimeException
	 */
	public static function decode(string $binary): SegmentListItem {
		if (strlen($binary) != SegmentListItem::SIZE) {
			throw new \RuntimeException(sprintf("Invalid segment list item size: %s", strlen($binary)));
		}
		$unpacked = unpack('Nkey/Nsegment/Ndata/Ntimestamp', $binary);
		$key = $unpacked['key'];
		$segmentSize = $unpacked['segment'];
		$dataSize = $unpacked['data'];
		$timestamp = $unpacked['timestamp'];

		return new SegmentListItem($key, $segmentSize, $dataSize, $timestamp);
	}

	/**
	 * Encode segment list item to binary
	 * @param SegmentListItem $item
	 * @return string
	 */
	public static function encode(SegmentListItem $item): string {
		$data = pack("NNNN", $item->getKey(), $item->getSegmentSize(), $item->getDataSize(), $item->getTimestamp());
		return $data;
	}
}
