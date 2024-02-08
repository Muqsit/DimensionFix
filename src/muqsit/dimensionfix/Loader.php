<?php

declare(strict_types=1);

namespace muqsit\dimensionfix;

use InvalidArgumentException;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use ReflectionClass;
use function spl_object_id;

final class Loader extends PluginBase{

	/** @var array<string, DimensionIds::*> */
	private array $applicable_worlds = [];

	/** @var Compressor[] */
	private array $known_compressors = [];

	protected function onEnable() : void{
		$this->saveDefaultConfig();

		$this->getServer()->getPluginManager()->registerEvent(PlayerLoginEvent::class, function(PlayerLoginEvent $event) : void{
			$this->registerKnownCompressor($event->getPlayer()->getNetworkSession()->getCompressor());
		}, EventPriority::LOWEST, $this);
		$this->getServer()->getPluginManager()->registerEvent(WorldLoadEvent::class, function(WorldLoadEvent $event) : void{
			$this->registerHackToWorldIfApplicable($event->getWorld());
		}, EventPriority::LOWEST, $this);

		// register already-registered values
		$this->registerKnownCompressor(ZlibCompressor::getInstance());

		foreach($this->getConfig()->get("apply-to-worlds") as $world_folder_name => $dimension_id){
			$this->applyToWorld($world_folder_name, match($dimension_id){
				"end" => DimensionIds::THE_END,
				"nether" => DimensionIds::NETHER,
				default => throw new InvalidArgumentException("Invalid dimension ID in configuration: {$dimension_id}")
			});
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
		if(!isset($this->applicable_worlds[$world_name = $world->getFolderName()])){
			return false;
		}

		$dimension_id = $this->applicable_worlds[$world_name];
		$this->registerHackToWorld($world, $dimension_id);
		return true;
	}

	/**
	 * @param World $world
	 * @param DimensionIds::* $dimension_id
	 */
	private function registerHackToWorld(World $world, int $dimension_id) : void{
		/** @see ChunkCache::$instances */
		static $_chunk_cache = new ReflectionClass(ChunkCache::class);

		foreach($this->known_compressors as $compressor){
			$chunk_cache = ChunkCache::getInstance($world, $compressor);
			if(!($chunk_cache instanceof DimensionChunkCache)){
				$instances = $_chunk_cache->getStaticPropertyValue("instances");
				$instances[spl_object_id($world)][spl_object_id($compressor)] = DimensionChunkCache::from($chunk_cache, $dimension_id);
				$_chunk_cache->setStaticPropertyValue("instances", $instances);
			}
		}
	}

	/**
	 * @param string $world_folder_name
	 * @param DimensionIds::* $dimension_id
	 */
	public function applyToWorld(string $world_folder_name, int $dimension_id) : void{
		$this->applicable_worlds[$world_folder_name] = $dimension_id;
		$world = $this->getServer()->getWorldManager()->getWorldByName($world_folder_name);
		if($world !== null){
			$this->registerHackToWorldIfApplicable($world);
		}
	}

	public function unapplyFromWorld(string $world_folder_name) : void{
		unset($this->applicable_worlds[$world_folder_name]);
	}
}