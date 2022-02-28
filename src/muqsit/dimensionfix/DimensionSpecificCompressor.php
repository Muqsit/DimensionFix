<?php

declare(strict_types=1);

namespace muqsit\dimensionfix;

use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\serializer\ChunkSerializer;

final class DimensionSpecificCompressor implements Compressor{

	public function __construct(
		private Compressor $inner
	){}

	public function willCompress(string $data) : bool{
		return $this->inner->willCompress($data);
	}

	public function decompress(string $payload) : string{
		return $this->inner->decompress($payload);
	}

	public function compress(string $payload) : string{
		$context = new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary());
		foreach((new PacketBatch($payload))->getPackets(PacketPool::getInstance(), $context, 1) as [$packet, $buffer]){
			if($packet instanceof LevelChunkPacket){
				$packet->decode(PacketSerializer::decoder($buffer, 0, $context));
				$payload = PacketBatch::fromPackets($context, LevelChunkPacket::create(
					$packet->getChunkX(),
					$packet->getChunkZ(),
					$packet->getSubChunkCount() - ChunkSerializer::LOWER_PADDING_SIZE,
					$packet->isClientSubChunkRequestEnabled(),
					$packet->getUsedBlobHashes(),
					substr($packet->getExtraPayload(), ChunkSerializer::LOWER_PADDING_SIZE * 2)
				))->getBuffer();
			}
		}
		return $this->inner->compress($payload);
	}
}