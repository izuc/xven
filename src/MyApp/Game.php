<?php

namespace MyApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Game implements MessageComponentInterface
{
    // Core properties
    protected $clients;
    protected $players;
    protected $mapPlayers;
    private $lastBroadcastTime = 0;
    private $movedPlayers = [];

    // Database connection and connection pool
    private $db; // Current connection from the pool
    private $dbPool = [];
    private const MAX_DB_CONNECTIONS = 10;

    // Cache properties
    private $mapCache = [];
    private $mapCacheTimestamps = [];
    private const CACHE_TTL = 300; // 5 minutes in seconds

    // Request queue management
    private $requestQueue = [];
    private const MAX_QUEUE_SIZE = 1000;

    // Performance monitoring
    private $metrics = [
        'player_moves'    => 0,
        'map_transitions' => 0,
        'db_queries'      => 0,
    ];

    // Rate limiting and connection management
    private $playerLastAction = [];
    private $actionRateLimit = 0.05; // 50ms between actions

    // Periodic cleanup
    private $lastCleanupTime = 0;
    private const CLEANUP_INTERVAL = 300; // 5 minutes

    // Constants for map dimensions and player sizes
    private const MAP_WIDTH = 25;
    private const MAP_HEIGHT = 20;
    private const TILE_SIZE = 32;
    private const MAX_PLAYERS_PER_MAP = 50;
    private const CONNECTION_TIMEOUT = 30;
    
    // Walkable tiles definition
    private $walkableTiles = [
        [1, 1], // Dirt

        [0, 0], // top left of green area, dirt in bottom right corner
        [0, 1], // top middle of green area, dirt at the bottom
        [0, 2], // top right of green area, dirt at the bottom left corner
        [1, 0], // left middle of green area, dirt at the right
        [1, 2], // right middle of green area, dirt at the left
        [2, 0], // bottom left of green area, dirt at the top right corner
        [2, 1], // bottom middle of green area, dirt at the top
        [2, 2], // bottom right of green area, dirt at the top left corner

        [0, 3], // top left of dirt with grass corner in bottom right
        [0, 4], // top right of dirt with grass corner in bottom left
        [1, 3], // bottom left of dirt with grass corner in top right
        [1, 4], // bottom right of dirt with grass corner in top left

        [0, 6], // Grass with 3 small blue flowers on the ground
        [0, 7], // Grass with 2 small red flowers on the ground
        [0, 8], // Grass with 3 small blue flowers (different orientations)
        [1, 6], // Grass with bigger lighter green leaves
        [1, 7], // Grass with 1 blue flower
        [1, 8], // Grass with bigger lighter green leaves (subtle differences)

        [4, 6],
        [4, 7],
        [4, 8],
        [4, 9], // Normal grass
        [5, 6],
        [5, 7],
        [5, 8],
        [5, 9], // Normal grass with variations
    ];

    public function __construct()
    {
        $this->clients    = new \SplObjectStorage();
        $this->players    = [];
        $this->mapPlayers = [];

        // Validate critical configuration.
        $this->validateConfiguration();

        // Set up the database connection from our connection pool.
        $this->db = $this->getDBConnection();
    }

    /**
     * Validate configuration constants.
     */
    private function validateConfiguration()
    {
        if (self::TILE_SIZE <= 0 || self::MAP_WIDTH <= 0 || self::MAP_HEIGHT <= 0) {
            throw new \InvalidArgumentException("Invalid map configuration");
        }
    }

    /**
     * Returns a PDO connection from the connection pool.
     */
    private function getDBConnection()
    {
        $threadId = getmypid();
        if (!isset($this->dbPool[$threadId])) {
            if (count($this->dbPool) >= self::MAX_DB_CONNECTIONS) {
                throw new \RuntimeException("Maximum database connections reached");
            }
            $this->dbPool[$threadId] = $this->initializeDatabase();
        }
        return $this->dbPool[$threadId];
    }

    /**
     * Initialize the database connection.
     */
    private function initializeDatabase()
	{
		try {
			// Load environment variables
			$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
			$dotenv->load();
			
			$config = [
				'host'     => $_ENV['DB_HOST'],
				'dbname'   => $_ENV['DB_NAME'],
				'username' => $_ENV['DB_USER'],
				'password' => $_ENV['DB_PASS'],
				'options'  => [
					\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
					\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
					\PDO::ATTR_EMULATE_PREPARES   => false,
				],
			];

			$pdo = new \PDO(
				"mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4",
				$config['username'],
				$config['password'],
				$config['options']
			);
			$this->updateMetrics('db_queries');
			return $pdo;
		} catch (\PDOException $e) {
			$this->logError("Database connection failed", $e);
			throw new \RuntimeException("Could not connect to database");
		}
	}

    /**
     * Centralized error logging.
     * Logs both to system error log and to a local file.
     */
    private function logError($message, \Exception $e = null)
    {
        // Create logs directory if it doesn't exist
        $logDir = __DIR__ . '/../../logs';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $logFile = $logDir . '/game.log';
        $errorMsg = date('Y-m-d H:i:s') . " - " . $message;
        if ($e) {
            $errorMsg .= " Exception: " . $e->getMessage() . " Stack trace: " . $e->getTraceAsString();
        }
        $errorMsg .= "\n";

        file_put_contents($logFile, $errorMsg, FILE_APPEND);
        error_log($errorMsg);
    }

    public function onOpen(ConnectionInterface $conn)
    {
        try {
            $this->enforceConnectionLimits();
        } catch (\Exception $e) {
            $conn->send(json_encode([
                "type"    => "error",
                "message" => $e->getMessage()
            ]));
            $conn->close();
            return;
        }

        $this->clients->attach($conn);
        $this->cleanupInactiveConnections();
        // Wait for client to send a 'register' message.
    }

    /**
     * Enforce maximum connections.
     */
    private function enforceConnectionLimits()
    {
        $maxConnections = 1000;
        if ($this->clients->count() >= $maxConnections) {
            throw new \RuntimeException("Server at maximum capacity");
        }
    }

    /**
     * Cleanup inactive connections.
     */
    private function cleanupInactiveConnections()
    {
        foreach ($this->clients as $client) {
            // Placeholder: check client connection state if available.
        }
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        try {
            $data = json_decode($msg, true);
            if (isset($data["type"])) {
                switch ($data["type"]) {
                    case 'register':
                        if (isset($data["id"])) {
                            $playerId = $data["id"];
                            $this->registerPlayer($from, $playerId);
                        } else {
                            $this->logError("Player ID is missing during registration.");
                        }
                        break;
                    case 'move':
                        if (isset($data["id"])) {
                            $playerId = $data["id"];
                            if (!$this->checkRateLimit($playerId)) {
                                return;
                            }
                            $newX   = $data["x"];
                            $newY   = $data["y"];
                            $facing = $data["facing"] ?? "down";
                            $this->validatePlayerMovement($newX, $newY, $facing);
                            $frame = $data["frame"] ?? 0;
                            $this->movePlayer($playerId, $newX, $newY, $facing, $frame);
                        } else {
                            $this->logError("Player ID is missing during movement.");
                        }
                        break;
                    case 'character_index':
                        if (isset($data["id"])) {
                            $playerId       = $data["id"];
                            $characterIndex = $data["character_index"];
                            $this->updatePlayerCharacterIndex($playerId, $characterIndex);
                        } else {
                            $this->logError("Player ID is missing during character index update.");
                        }
                        break;
                    case 'get_map':
                        if (isset($data["mapId"])) {
                            $mapId = $data["mapId"];
                            $mapData = $this->loadMap($mapId);
                            if ($mapData !== null) {
                                $from->send(json_encode([
                                    "type" => "mapData",
                                    "data" => $mapData,
                                ]));
                            }
                        }
                        break;
                    case 'get_position':
                        if (isset($data["id"])) {
                            $playerId = $data["id"];
                            if (isset($this->players[$playerId])) {
                                $player = $this->players[$playerId];
                                $from->send(json_encode([
                                    "type" => "position",
                                    "id"   => $playerId,
                                    "x"    => $player["x"],
                                    "y"    => $player["y"],
                                ]));
                            }
                        }
                        break;
                    case 'get_players':
                        if (isset($data["mapId"])) {
                            $mapId = $data["mapId"];
                            $playersData = $this->getPlayersData($mapId);
                            $from->send(json_encode([
                                "type"    => "playersData",
                                "players" => $playersData,
                            ]));
                        }
                        break;
                    default:
                        $this->logError("Unknown message type: " . $data["type"]);
                        break;
                }
            } else {
                $this->logError("Invalid message format.");
            }
        } catch (\Exception $e) {
            $this->handleException($from, $e);
        }

        $this->updateInactivePlayers();
        $this->periodicCleanup();
    }

    /**
     * Validate movement input.
     * 
     * Modified to allow a one-tile buffer outside of normal map boundaries for transitions.
     */
    private function validatePlayerMovement($x, $y, $facing)
    {
        if (!is_numeric($x) || !is_numeric($y)) {
            throw new \InvalidArgumentException("Invalid coordinates");
        }

        $validFacings = ['up', 'down', 'left', 'right'];
        if (!in_array($facing, $validFacings)) {
            throw new \InvalidArgumentException("Invalid facing direction");
        }

        // Allow a one-tile buffer beyond the map boundaries
        $buffer = self::TILE_SIZE;
        if ($x < -$buffer || $x > (self::MAP_WIDTH * self::TILE_SIZE + $buffer) ||
            $y < -$buffer || $y > (self::MAP_HEIGHT * self::TILE_SIZE + $buffer)) {
            throw new \InvalidArgumentException("Coordinates out of bounds");
        }
    }

    /**
     * Rate limiting per player.
     */
    private function checkRateLimit($playerId)
    {
        $currentTime = microtime(true);
        if (isset($this->playerLastAction[$playerId])) {
            $timeDiff = $currentTime - $this->playerLastAction[$playerId];
            if ($timeDiff < $this->actionRateLimit) {
                return false;
            }
        }
        $this->playerLastAction[$playerId] = $currentTime;
        return true;
    }

    /**
     * Cleanup old player data.
     */
    private function cleanupOldData()
    {
        $cutoffTime = time() - 3600;
        foreach ($this->players as $playerId => $player) {
            if ($player['last_active'] < $cutoffTime) {
                unset($this->players[$playerId]);
                unset($this->playerLastAction[$playerId]);
            }
        }
    }

    /**
     * Check memory usage and clear caches if necessary.
     */
    private function checkMemoryUsage()
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        if ($memoryUsage > $this->convertToBytes($memoryLimit) * 0.9) {
            $this->clearCaches();
        }
    }

    /**
     * Convert memory string (e.g., "128M") to bytes.
     */
    private function convertToBytes($val)
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int)$val;
        switch ($last) {
            case 'g':
                $num *= 1024;
            case 'm':
                $num *= 1024;
            case 'k':
                $num *= 1024;
        }
        return $num;
    }

    /**
     * Clear caches (e.g., map cache).
     */
    private function clearCaches()
    {
        $this->mapCache = [];
        $this->mapCacheTimestamps = [];
    }

    /**
     * Update performance metrics.
     */
    private function updateMetrics($metric)
    {
        if (isset($this->metrics[$metric])) {
            $this->metrics[$metric]++;
        }
    }

    /**
     * Log performance metrics (every 5 minutes).
     */
    private function logPerformanceMetrics()
    {
        if (time() % 300 === 0) {
            $this->logError("Performance metrics: " . json_encode($this->metrics));
            $this->metrics = array_fill_keys(array_keys($this->metrics), 0);
        }
    }

    /**
     * Queue a request.
     */
    private function queueRequest($playerId, $request)
    {
        if (count($this->requestQueue) >= self::MAX_QUEUE_SIZE) {
            throw new \RuntimeException("Request queue full");
        }
        $this->requestQueue[] = ['playerId' => $playerId, 'request' => $request];
    }

    /**
     * Process queued requests.
     */
    private function processRequestQueue()
    {
        while (!empty($this->requestQueue)) {
            $request = array_shift($this->requestQueue);
            // Process request as needed.
        }
    }

    /**
     * Perform periodic cleanup tasks.
     */
    private function periodicCleanup()
    {
        $currentTime = time();
        if ($currentTime - $this->lastCleanupTime >= self::CLEANUP_INTERVAL) {
            $this->cleanupOldData();
            $this->cleanupInactiveConnections();
            $this->checkMemoryUsage();
            $this->logPerformanceMetrics();
            $this->processRequestQueue();
            $this->lastCleanupTime = $currentTime;
        }
    }

    /**
     * Handle exceptions with detailed logging.
     */
    private function handleException(ConnectionInterface $conn, \Exception $e)
    {
        $errorCode = $this->getErrorCode($e);
        $this->logError("Error $errorCode occurred", $e);
        if ($this->isClientError($errorCode)) {
            $conn->send(json_encode([
                'type'    => 'error',
                'code'    => $errorCode,
                'message' => $this->getClientMessage($errorCode)
            ]));
        } else {
            $conn->send(json_encode([
                'type'    => 'error',
                'message' => 'An internal error occurred'
            ]));
        }
    }

    /**
     * Extract error code.
     */
    private function getErrorCode(\Exception $e)
    {
        return $e->getCode() ?: 500;
    }

    /**
     * Check if error code is a client error.
     */
    private function isClientError($errorCode)
    {
        return $errorCode >= 400 && $errorCode < 500;
    }

    /**
     * Get client-friendly error message.
     */
    private function getClientMessage($errorCode)
    {
        switch ($errorCode) {
            case 400:
                return "Bad Request";
            case 404:
                return "Not Found";
            default:
                return "Client error occurred";
        }
    }

    /**
     * Load map data with caching.
     */
    private function loadMap($mapId)
    {
        $currentTime = time();
        if (isset($this->mapCache[$mapId]) &&
            isset($this->mapCacheTimestamps[$mapId]) &&
            $currentTime - $this->mapCacheTimestamps[$mapId] < self::CACHE_TTL) {
            return json_decode($this->mapCache[$mapId], true);
        }
        try {
            $stmt = $this->getDBConnection()->prepare("SELECT data FROM maps WHERE id = :mapId");
            $stmt->bindValue(":mapId", $mapId, \PDO::PARAM_INT);
            $stmt->execute();
            if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $this->mapCache[$mapId] = $row["data"];
                $this->mapCacheTimestamps[$mapId] = $currentTime;
                $this->updateMetrics('db_queries');
                return json_decode($row["data"], true);
            }
        } catch (\Exception $e) {
            $this->logError("Error loading map", $e);
        }
        return null;
    }

    /**
     * Update player's character index.
     */
    private function updatePlayerCharacterIndex($playerId, $characterIndex)
    {
        if (isset($this->players[$playerId])) {
            try {
                $this->players[$playerId]["character_index"] = $characterIndex;
                $stmt = $this->getDBConnection()->prepare(
                    "UPDATE players SET character_index = :character_index WHERE id = :id"
                );
                $stmt->bindValue(":character_index", $characterIndex, \PDO::PARAM_STR);
                $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                $stmt->execute();
                $this->updateMetrics('db_queries');
                $this->broadcastPlayerPositions($this->players[$playerId]["map_id"]);
            } catch (\Exception $e) {
                $this->logError("Error updating player character index", $e);
            }
        }
    }

    public function registerPlayer(ConnectionInterface $conn, $playerId)
    {
        try {
            if (!isset($this->players[$playerId])) {
                $stmt = $this->getDBConnection()->prepare("SELECT * FROM players WHERE id = :id");
                $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                $stmt->execute();
                $this->updateMetrics('db_queries');
                if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $this->players[$playerId] = [
                        "id"              => $playerId,
                        "x"               => $row["x"],
                        "y"               => $row["y"],
                        "width"           => 32,
                        "height"          => 32,
                        "character_index" => $row["character_index"],
                        "facing"          => $row["facing"],
                        "frame"           => $row["frame"],
                        "map_id"          => $row["map_id"],
                        "status"          => $row["status"],
                        "last_active"     => time(),
                        "connection"      => $conn,
                    ];
                    $mapData    = $this->loadMap($row["map_id"]);
                    $playersData = $this->getPlayersData($row["map_id"]);
                    $conn->send(json_encode([
                        "type"    => "init",
                        "player"  => $this->players[$playerId],
                        "mapData" => $mapData,
                        "players" => $playersData,
                        "map_id"  => $row["map_id"],
                    ]));
                } else {
                    $this->createNewPlayer($playerId, $conn);
                    $mapData    = $this->loadMap(1);
                    $playersData = $this->getPlayersData(1);
                    $conn->send(json_encode([
                        "type"    => "init",
                        "player"  => $this->players[$playerId],
                        "mapData" => $mapData,
                        "players" => $playersData,
                        "map_id"  => 1,
                    ]));
                }
                $this->addToMap($playerId, $this->players[$playerId]["map_id"]);
            }
            $this->broadcastPlayerPositions($this->players[$playerId]["map_id"]);
        } catch (\Exception $e) {
            $this->handleException($conn, $e);
        }
    }

    /**
     * Create a new player.
     */
    private function createNewPlayer($playerId, ConnectionInterface $conn, $characterIndex = 0)
    {
        try {
            $width    = 32;
            $height   = 32;
            $position = $this->findNonOverlappingPosition($width, $height);
            $initialFrame = 0;
            $initialMapId = 1;
            $this->players[$playerId] = [
                "id"              => $playerId,
                "x"               => $position["x"],
                "y"               => $position["y"],
                "width"           => $width,
                "height"          => $height,
                "character_index" => $characterIndex,
                "facing"          => "down",
                "frame"           => $initialFrame,
                "map_id"          => $initialMapId,
                "status"          => 1,
                "last_active"     => time(),
                "connection"      => $conn,
            ];
            $stmt = $this->getDBConnection()->prepare(
                "INSERT INTO players (id, x, y, width, height, character_index, facing, frame, map_id, status, last_active) 
                 VALUES (:id, :x, :y, :width, :height, :character_index, :facing, :frame, :map_id, :status, :last_active)"
            );
            $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
            $stmt->bindValue(":x", $position["x"], \PDO::PARAM_INT);
            $stmt->bindValue(":y", $position["y"], \PDO::PARAM_INT);
            $stmt->bindValue(":width", $width, \PDO::PARAM_INT);
            $stmt->bindValue(":height", $height, \PDO::PARAM_INT);
            $stmt->bindValue(":character_index", $characterIndex, \PDO::PARAM_INT);
            $stmt->bindValue(":facing", "down", \PDO::PARAM_STR);
            $stmt->bindValue(":frame", $initialFrame, \PDO::PARAM_INT);
            $stmt->bindValue(":map_id", $initialMapId, \PDO::PARAM_INT);
            $stmt->bindValue(":status", 1, \PDO::PARAM_INT);
            $stmt->bindValue(":last_active", time(), \PDO::PARAM_INT);
            $stmt->execute();
            $this->updateMetrics('db_queries');
        } catch (\Exception $e) {
            $this->logError("Error creating new player", $e);
        }
    }

    /**
     * Broadcast that a player has left a map.
     */
    private function broadcastPlayerLeaveMap($playerId, $mapId)
    {
        $payload = json_encode([
            "type"     => "playerLeaveMap",
            "playerId" => $playerId,
        ]);
        if (isset($this->mapPlayers[$mapId])) {
            foreach ($this->mapPlayers[$mapId] as $id) {
                if (isset($this->players[$id])) {
                    $conn = $this->players[$id]["connection"];
                    if ($conn !== null) {
                        try {
                            $conn->send($payload);
                        } catch (\Exception $e) {
                            $this->logError("Error broadcasting player leave map", $e);
                        }
                    }
                }
            }
        }
    }

    /**
     * Handle player movement.
     */
    private function movePlayer($playerId, $newX, $newY, $facing, $frame = 0)
    {
        $this->updateMetrics('player_moves');
        if (isset($this->players[$playerId])) {
            $player = &$this->players[$playerId];
            $currentTime = time();
            $player["last_active"] = $currentTime;
            if ($player["status"] == 0) {
                $player["status"] = 1;
                try {
                    $stmt = $this->getDBConnection()->prepare(
                        "UPDATE players SET status = 1, last_active = :last_active WHERE id = :id"
                    );
                    $stmt->bindValue(":last_active", $currentTime, \PDO::PARAM_INT);
                    $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                    $stmt->execute();
                    $this->updateMetrics('db_queries');
                } catch (\Exception $e) {
                    $this->logError("Error updating player status", $e);
                }
            }

            // Check for map transitions with detailed logging.
            $transitionData = $this->checkMapTransitions($player, $newX, $newY);
            if ($transitionData !== null) {
                $player["connection"]->send(json_encode([
                    "type" => "enteringNewMap",
                ]));
                $oldMapId = $player["map_id"];
                $player["map_id"] = $transitionData["map_id"];
                $player["x"] = $transitionData["x"];
                $player["y"] = $transitionData["y"];
                $this->updateMetrics('map_transitions');
                try {
                    $stmt = $this->getDBConnection()->prepare(
                        "UPDATE players SET map_id = :map_id, x = :x, y = :y WHERE id = :id"
                    );
                    $stmt->bindValue(":map_id", $player["map_id"], \PDO::PARAM_INT);
                    $stmt->bindValue(":x", $player["x"], \PDO::PARAM_INT);
                    $stmt->bindValue(":y", $player["y"], \PDO::PARAM_INT);
                    $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                    $stmt->execute();
                    $this->updateMetrics('db_queries');
                } catch (\Exception $e) {
                    $this->logError("Error updating player map_id", $e);
                }
                $this->removeFromMap($playerId, $oldMapId);
                $this->addToMap($playerId, $player["map_id"]);
                $otherPlayers = $this->getPlayersData($player["map_id"]);
                unset($otherPlayers[$playerId]);
                $mapData = $this->loadMap($player["map_id"]);
                if ($mapData !== null) {
                    $player["connection"]->send(json_encode([
                        "type"    => "mapData",
                        "data"    => $mapData,
                        "map_id"  => $player["map_id"],
                        "id"      => $playerId,
                        "x"       => $player["x"],
                        "y"       => $player["y"],
                        "players" => $otherPlayers,
                    ]));
                } else {
                    $this->logError("Map data is null for map ID: " . $player["map_id"]);
                }
                $this->broadcastPlayerLeaveMap($playerId, $oldMapId);
                $this->movedPlayers[$playerId] = true;
            } else {
                $this->handleStandardMovement($playerId, $newX, $newY, $facing, $frame);
                try {
                    $stmt = $this->getDBConnection()->prepare(
                        "UPDATE players SET last_active = :last_active WHERE id = :id"
                    );
                    $stmt->bindValue(":last_active", $currentTime, \PDO::PARAM_INT);
                    $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                    $stmt->execute();
                    $this->updateMetrics('db_queries');
                } catch (\Exception $e) {
                    $this->logError("Error updating player last_active", $e);
                }
                $this->movedPlayers[$playerId] = true;
            }
            $currentTimeMicro = microtime(true);
            if ($currentTimeMicro - $this->lastBroadcastTime >= 0.05) {
                $this->broadcastMovedPlayerPositions();
                $this->lastBroadcastTime = $currentTimeMicro;
            }
        }
    }

    /**
     * Update inactive players.
     */
    private function updateInactivePlayers()
    {
        $inactiveTime = 300;
        foreach ($this->players as $playerId => $player) {
            if ($player["status"] == 1 && time() - $player["last_active"] >= $inactiveTime) {
                $player["status"] = 0;
                try {
                    $stmt = $this->getDBConnection()->prepare(
                        "UPDATE players SET status = 0 WHERE id = :id"
                    );
                    $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                    $stmt->execute();
                    $this->updateMetrics('db_queries');
                } catch (\Exception $e) {
                    $this->logError("Error updating inactive player status", $e);
                }
            }
        }
    }

    /**
     * Batch broadcast positions for moved players.
     */
    private function broadcastMovedPlayerPositions()
    {
        foreach ($this->movedPlayers as $playerId => $moved) {
            if ($moved) {
                $mapId = $this->players[$playerId]["map_id"];
                $this->broadcastPlayerPositions($mapId);
                $this->movedPlayers[$playerId] = false;
            }
        }
    }

    private function addToMap($playerId, $mapId)
    {
        if (!isset($this->mapPlayers[$mapId])) {
            $this->mapPlayers[$mapId] = [];
        }
        $this->mapPlayers[$mapId][$playerId] = $playerId;
    }

    private function removeFromMap($playerId, $mapId)
    {
        unset($this->mapPlayers[$mapId][$playerId]);
        $this->broadcastPlayerPositions($mapId);
    }

    /**
     * Handle standard (non-transition) movement.
     */
    private function handleStandardMovement($playerId, $newX, $newY, $facing, $frame)
    {
        $player = &$this->players[$playerId];
        $collision = false;
        foreach ($this->players as $otherId => $otherPlayer) {
            if (
                $otherId !== $playerId &&
                $otherPlayer["map_id"] === $player["map_id"] &&
                $this->isColliding(
                    $newX,
                    $newY,
                    $player["width"],
                    $player["height"],
                    $otherPlayer["x"],
                    $otherPlayer["y"],
                    $otherPlayer["width"],
                    $otherPlayer["height"]
                )
            ) {
                $collision = true;
                break;
            }
        }
        if (
            !$collision &&
            $this->isCollidingWithMap($newX, $newY, $player["width"], $player["height"], $player["map_id"])
        ) {
            $collision = true;
        }
        if (!$collision) {
            $player["x"] = $newX;
            $player["y"] = $newY;
            $player["facing"] = $facing;
            $player["frame"]  = $frame ?? 0;
            try {
                $stmt = $this->getDBConnection()->prepare(
                    "UPDATE players SET x = :x, y = :y, facing = :facing, frame = :frame WHERE id = :id"
                );
                $stmt->bindValue(":x", $newX, \PDO::PARAM_INT);
                $stmt->bindValue(":y", $newY, \PDO::PARAM_INT);
                $stmt->bindValue(":facing", $facing, \PDO::PARAM_STR);
                $stmt->bindValue(":frame", $player["frame"], \PDO::PARAM_INT);
                $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                $stmt->execute();
                $this->updateMetrics('db_queries');
            } catch (\Exception $e) {
                $this->logError("Error updating player position", $e);
            }
            $this->broadcastPlayerPosition($playerId);
        }
    }

    /**
     * Check if movement triggers a map transition.
     *
     * Modified to log details and adjust upward/downward coordinates.
     */
    private function checkMapTransitions($player, $newX, $newY)
    {
        $currentMapId = $player["map_id"];
        $tileSize = self::TILE_SIZE;
        $mapWidth = self::MAP_WIDTH;
        $mapHeight = self::MAP_HEIGHT;

        $this->logError("Map transition check - Map: $currentMapId, Position: ($newX, $newY)");

        // If moving upward
        if ($newY < 0) {
            $this->logError("Attempting upward transition from map $currentMapId");
            $transitionY = ($mapHeight * $tileSize) - $player["height"];
            return $this->handleTransition($currentMapId, "map_top", $newX, $transitionY);
        }
        // If moving downward
        if ($newY + $player["height"] > $mapHeight * $tileSize) {
            $this->logError("Attempting downward transition from map $currentMapId");
            return $this->handleTransition($currentMapId, "map_bottom", $newX, 0);
        }
        return null;
    }

    /**
     * Handle map transition.
     *
     * Verifies target map exists and has proper reverse connection.
     */
    private function handleTransition($currentMapId, $directionField, $x, $y)
    {
        try {
            $stmt = $this->getDBConnection()->prepare("SELECT $directionField FROM maps WHERE id = ?");
            $stmt->execute([$currentMapId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            $this->logError("Transition check - From Map: $currentMapId, Direction: $directionField, Result: " . json_encode($result));

            if ($result && $result[$directionField]) {
                $targetMapId = $result[$directionField];
                $reverseDirection = $this->getReverseDirection($directionField);
                $stmt = $this->getDBConnection()->prepare("SELECT id FROM maps WHERE id = ? AND $reverseDirection = ?");
                $stmt->execute([$targetMapId, $currentMapId]);
                $targetMap = $stmt->fetch();
                if ($targetMap) {
                    $this->logError("Transition approved - From Map $currentMapId to Map $targetMapId");
                    return [
                        "map_id" => $targetMapId,
                        "x"      => $x,
                        "y"      => $y,
                    ];
                } else {
                    $this->logError("Target map $targetMapId does not have proper reverse connection");
                }
            }
            $this->logError("No valid transition found");
            return null;
        } catch (\Exception $e) {
            $this->logError("Error handling map transition", $e);
            return null;
        }
    }

    /**
     * Get the reverse connection direction.
     */
    private function getReverseDirection($direction)
    {
        $pairs = [
            'map_top' => 'map_bottom',
            'map_bottom' => 'map_top',
            'map_left' => 'map_right',
            'map_right' => 'map_left'
        ];
        return $pairs[$direction] ?? null;
    }

    /**
     * Check for collision with map boundaries and non-walkable tiles.
     *
     * Added detailed logging for boundary checks and allows transition checks.
     */
    private function isCollidingWithMap($x, $y, $width, $height, $mapId)
    {
        $tileSize = self::TILE_SIZE;
        $mapWidth = self::MAP_WIDTH;
        $mapHeight = self::MAP_HEIGHT;

        if ($y < 0) {
            $this->logError("Player attempting to move above map boundary: y=$y");
            return false; // Allow transition checks
        }
        if ($y + $height > $mapHeight * $tileSize) {
            $this->logError("Player attempting to move below map boundary: y=$y, height=$height");
            return false; // Allow transition checks
        }

        $leftX   = floor($x / $tileSize);
        $rightX  = floor(($x + $width - 1) / $tileSize);
        $topY    = floor($y / $tileSize);
        $bottomY = floor(($y + $height - 1) / $tileSize);

        if ($x < 0 || $x + $width > $mapWidth * $tileSize ||
            $y < 0 || $y + $height > $mapHeight * $tileSize) {
            return true;
        }

        $tileData = $this->loadMap($mapId);
        if ($tileData === null) {
            $this->logError("Error: Map data is null for map ID $mapId");
            return true;
        }

        for ($row = $topY; $row <= $bottomY; $row++) {
            for ($col = $leftX; $col <= $rightX; $col++) {
                if ($row < 0 || $row >= $mapHeight || $col < 0 || $col >= $mapWidth) {
                    continue;
                }
                if (!isset($tileData[$row][$col])) {
                    continue;
                }
                if (!$this->isWalkable($tileData[$row][$col][0], $tileData[$row][$col][1])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Determine if a tile is walkable.
     */
    private function isWalkable($tileRow, $tileCol)
    {
        foreach ($this->walkableTiles as $tile) {
            if ($tileRow === $tile[0] && $tileCol === $tile[1]) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find a non-overlapping starting position.
     */
    private function findNonOverlappingPosition($width, $height)
    {
        $mapWidth  = self::MAP_WIDTH * self::TILE_SIZE;
        $mapHeight = self::MAP_HEIGHT * self::TILE_SIZE;
        $maxAttempts = 100;
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $x = rand($mapWidth / 4, ($mapWidth * 3) / 4 - $width);
            $y = rand($mapHeight / 4, ($mapHeight * 3) / 4 - $height);
            $overlaps = false;
            foreach ($this->players as $player) {
                if ($player["status"] == 1) {
                    if ($this->isColliding(
                        $x,
                        $y,
                        $width,
                        $height,
                        $player["x"],
                        $player["y"],
                        $player["width"],
                        $player["height"]
                    )) {
                        $overlaps = true;
                        break;
                    }
                }
            }
            if (!$overlaps) {
                return ["x" => $x, "y" => $y];
            }
            $attempt++;
        }
        return [
            "x" => ($mapWidth - $width) / 2,
            "y" => ($mapHeight - $height) / 2,
        ];
    }

    /**
     * Check if two rectangles collide.
     */
    private function isColliding($x1, $y1, $width1, $height1, $x2, $y2, $width2, $height2)
    {
        return $x1 < $x2 + $width2 &&
               $x1 + $width1 > $x2 &&
               $y1 < $y2 + $height2 &&
               $y1 + $height1 > $y2;
    }

    /**
     * Retrieve player data for a specific map.
     */
    private function getPlayersData($mapId)
    {
        $playersData = [];
        try {
            $stmt = $this->getDBConnection()->prepare("SELECT * FROM players WHERE map_id = :mapId AND status = 1");
            $stmt->bindValue(":mapId", $mapId, \PDO::PARAM_INT);
            $stmt->execute();
            $this->updateMetrics('db_queries');
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $playersData[$row["id"]] = [
                    "x"               => $row["x"],
                    "y"               => $row["y"],
                    "character_index" => $row["character_index"],
                    "facing"          => $row["facing"],
                    "frame"           => $row["frame"],
                    "map_id"          => $row["map_id"],
                    "status"          => $row["status"],
                ];
            }
        } catch (\Exception $e) {
            $this->logError("Error getting players data", $e);
        }
        return $playersData;
    }

    /**
     * Broadcast positions of all players on a given map.
     */
    private function broadcastPlayerPositions($mapId)
    {
        $playersData = $this->getPlayersData($mapId);
        $payload = json_encode([
            "type"    => "update",
            "players" => $playersData,
            "mapId"   => $mapId,
        ]);
        if (isset($this->mapPlayers[$mapId])) {
            foreach ($this->mapPlayers[$mapId] as $id) {
                if (isset($this->players[$id])) {
                    $conn = $this->players[$id]["connection"];
                    if ($conn !== null) {
                        try {
                            $conn->send($payload);
                        } catch (\Exception $e) {
                            $this->logError("Error broadcasting player positions", $e);
                        }
                    }
                }
            }
        }
    }

    /**
     * Broadcast a single player's position.
     */
    private function broadcastPlayerPosition($playerId)
    {
        if (!isset($this->players[$playerId])) {
            $this->logError("Error broadcasting player position: Player ID $playerId not found.");
            return;
        }
        $player = $this->players[$playerId];
        if ($player["connection"] === null) {
            $this->logError("Error broadcasting player position: Connection is null for Player ID $playerId.");
            return;
        }
        $mapId = $player["map_id"];
        $payload = json_encode([
            "type"   => "playerUpdate",
            "player" => [
                "id"              => $player["id"],
                "x"               => $player["x"],
                "y"               => $player["y"],
                "character_index" => $player["character_index"],
                "facing"          => $player["facing"],
                "frame"           => $player["frame"],
                "map_id"          => $player["map_id"],
                "width"           => $player["width"],
                "height"          => $player["height"],
                "status"          => $player["status"],
            ],
        ]);
        if (isset($this->mapPlayers[$mapId])) {
            foreach ($this->mapPlayers[$mapId] as $id) {
                if (isset($this->players[$id])) {
                    $conn = $this->players[$id]["connection"];
                    if ($conn !== null) {
                        try {
                            $conn->send($payload);
                        } catch (\Exception $e) {
                            $this->logError("Error broadcasting player position", $e);
                        }
                    }
                }
            }
        }
    }

    /**
     * Batch update example for multiple players.
     */
    private function batchUpdatePlayerPositions($updates)
    {
        $sql = "INSERT INTO players (id, x, y, facing) VALUES " .
               implode(',', array_fill(0, count($updates), '(?,?,?,?)')) .
               " ON DUPLICATE KEY UPDATE x=VALUES(x), y=VALUES(y), facing=VALUES(facing)";
        $params = [];
        foreach ($updates as $update) {
            $params = array_merge($params, [
                $update['id'], $update['x'], $update['y'], $update['facing']
            ]);
        }
        $stmt = $this->getDBConnection()->prepare($sql);
        $stmt->execute($params);
        $this->updateMetrics('db_queries');
    }

    /**
     * Transaction management example.
     */
    private function updatePlayerDataWithTransaction($playerId, $data)
    {
        try {
            $this->getDBConnection()->beginTransaction();
            $stmt = $this->getDBConnection()->prepare(
                "UPDATE players SET x = :x, y = :y, facing = :facing WHERE id = :id"
            );
            $stmt->execute([
                ':x'      => $data['x'],
                ':y'      => $data['y'],
                ':facing' => $data['facing'],
                ':id'     => $playerId
            ]);
            // Update other related data as needed...
            $this->getDBConnection()->commit();
        } catch (\Exception $e) {
            $this->getDBConnection()->rollBack();
            $this->logError("Transaction failed", $e);
            throw $e;
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        foreach ($this->players as $playerId => $player) {
            if ($player["connection"] === $conn) {
                $mapId = $player["map_id"];
                unset($this->players[$playerId]);
                $this->removeFromMap($playerId, $mapId);
                $this->broadcastPlayerPositions($mapId);
                break;
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->logError("An error has occurred: " . $e->getMessage(), $e);
        $conn->close();
    }
}
