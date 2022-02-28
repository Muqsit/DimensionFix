<?php

declare(strict_types=1);

namespace muqsit\dimensionfix;

use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use ReflectionProperty;

final class Loader extends PluginBase{

	/** @var string[] */
	private array $applicable_worlds = [];

	/** @var Compressor[] */
	private array $known_compressors = [];

	protected function onEnable() : void{
		$this->saveDefaultConfig();
		foreach($this->getConfig()->get("apply-to-worlds") as $world_folder_name){
			$this->applyToWorld($world_folder_name);
		}

		$this->getServer()->getPluginManager()->registerEvent(PlayerLoginEvent::class, function(PlayerLoginEvent $event) : void{
			$this->registerKnownCompressor($event->getPlayer()->getNetworkSession()->getCompressor());
		}, EventPriority::LOWEST, $this);
		$this->getServer()->getPluginManager()->registerEvent(WorldLoadEvent::class, function(WorldLoadEvent $event) : void{
			$this->registerHackToWorldIfApplicable($event->getWorld());
		}, EventPriority::LOWEST, $this);

		// register already-registered values
		$this->registerKnownCompressor(ZlibCompressor::getInstance());
		foreach($this->getServer()->getWorldManager()->getWorlds() as $world){
			$this->registerHackToWorldIfApplicable($world);
		}
	}

	private function registerKnownCompressor(Compressor $compressor) : void{
		if(isset($this->known_compressors[$id = spl_object_id($compressor)])){
			return;
		}

		$this->known_compressors[$id] = $compressor;
		foreach($this->getServer()->getWorldManager()->getWorlds() as $world){
			$this->registerHackToWorldIfApplicable($world);
		}
	}

	private function registerHackToWorldIfApplicable(World $world) : bool{
		if(!isset($this->applicable_worlds[$world->getFolderName()])){
			return false;
		}

		$this->registerHackToWorld($world);
		return true;
	}

	private function registerHackToWorld(World $world) : void{
		static $_chunk_cache_compressor = null;
		if($_chunk_cache_compressor === null){
			/** @see ChunkCache::$compressor */
			$_chunk_cache_compressor = new ReflectionProperty(ChunkCache::class, "compressor");
			$_chunk_cache_compressor->setAccessible(true);
		}

		foreach($this->known_compressors as $compressor){
			$chunk_cache = ChunkCache::getInstance($world, $compressor);
			$compressor = $_chunk_cache_compressor->getValue($chunk_cache);
			if(!($compressor instanceof DimensionSpecificCompressor)){
				$_chunk_cache_compressor->setValue($chunk_cache, new DimensionSpecificCompressor($compressor));
			}
		}
	}

	public function applyToWorld(string $world_folder_name) : void{
		$this->applicable_worlds[$world_folder_name] = $world_folder_name;
	}

	public function unapplyFromWorld(string $world_folder_name) : void{
		unset($this->applicable_worlds[$world_folder_name]);
	}
}