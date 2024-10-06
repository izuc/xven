<?php

namespace MyApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Game implements MessageComponentInterface
{
    protected $clients;
    protected $players;
    protected $mapPlayers;
    private $lastBroadcastTime = 0;
    private $movedPlayers = [];
    private $db;

    private const MAP_WIDTH = 25;
    private const MAP_HEIGHT = 20;
    private const TILE_SIZE = 32;

    private $walkableTiles = [
        [1, 1], // Dirt

        [0, 0], // top left of green area, dirt in bottom right corner
        [0, 1], // top middle of green area, dirt at the bottom
        [0, 2], // top right of the green area, dirt at the bottom left corner
        [1, 0], // left middle of the green area, dirt at the right
        [1, 2], // right of the green area, dirt at the left
        [2, 0], // bottom left of the green area, dirt at the top right corner
        [2, 1], // bottom middle of the green area, dirt at the top
        [2, 2], // bottom right of the green area, dirt at the top left corner

        [0, 3], // top left of the dirt with grass corner in bottom right
        [0, 4], // top right of the dirt with grass corner in bottom left
        [1, 3], // bottom left of the dirt with grass corner in top right
        [1, 4], // bottom right of the dirt with grass corner in top left

        [0, 6], // Grass with 3 small blue flowers on the ground
        [0, 7], // Grass with 2 small red flowers on the ground
        [0, 8], // Grass with 3 small blue flowers on the ground (flowers facing different direction)
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
        [5, 9], // Normal grass with different patterns
    ];

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->players = [];
        $this->mapPlayers = [];

        // Initialize the MySQL database connection
        $host = "localhost";
        $dbName = "xven";
        $username = "xven";
        $password = "*9PUb9T*Pas4My9[";

        try {
            $this->db = new \PDO(
                "mysql:host=$host;dbname=$dbName;charset=utf8",
                $username,
                $password
            );
            $this->db->setAttribute(
                \PDO::ATTR_ERRMODE,
                \PDO::ERRMODE_EXCEPTION
            );
        } catch (\PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            exit();
        }
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        // Wait for the client to send a 'register' message
    }

    private function getPlayerIdFromConnection(ConnectionInterface $conn)
    {
        foreach ($this->players as $playerId => $player) {
            if ($player["connection"] === $conn) {
                return $playerId;
            }
        }
        return null;
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
                            error_log("Player ID is missing during registration.");
                        }
                        break;
                    case 'move':
                        if (isset($data["id"])) {
                            $playerId = $data["id"];
                            $newX = $data["x"];
                            $newY = $data["y"];
                            $facing = $data["facing"] ?? "down";
                            $frame = $data["frame"] ?? 0;
                            $this->movePlayer($playerId, $newX, $newY, $facing, $frame);
                        } else {
                            error_log("Player ID is missing during movement.");
                        }
                        break;
                    case 'character_index':
                        if (isset($data["id"])) {
                            $playerId = $data["id"];
                            $characterIndex = $data["character_index"];
                            $this->updatePlayerCharacterIndex(
                                $playerId,
                                $characterIndex
                            );
                        } else {
                            error_log("Player ID is missing during character index update.");
                        }
                        break;
                    case 'get_map':
                        if (isset($data["mapId"])) {
                            $mapId = $data["mapId"];
                            $mapData = $this->loadMap($mapId);

                            if ($mapData !== null) {
                                $from->send(
                                    json_encode([
                                        "type" => "mapData",
                                        "data" => $mapData,
                                    ])
                                );
                            }
                        }
                        break;
                    case 'get_position':
                        if (isset($data["id"])) {
                            $playerId = $data["id"];
                            if (isset($this->players[$playerId])) {
                                $player = $this->players[$playerId];
                                $from->send(
                                    json_encode([
                                        "type" => "position",
                                        "id" => $playerId,
                                        "x" => $player["x"],
                                        "y" => $player["y"],
                                    ])
                                );
                            }
                        }
                        break;
                    case 'get_players':
                        if (isset($data["mapId"])) {
                            $mapId = $data["mapId"];
                            $playersData = $this->getPlayersData($mapId);
                            $from->send(
                                json_encode([
                                    "type" => "playersData",
                                    "players" => $playersData,
                                ])
                            );
                        }
                        break;
                    default:
                        error_log("Unknown message type: " . $data["type"]);
                        break;
                }
            } else {
                error_log("Invalid message format.");
            }
        } catch (\Exception $e) {
            $this->handleException($from, $e);
        }

        // Check and update the status of inactive players
        $this->updateInactivePlayers();
    }

    private function handleException(ConnectionInterface $conn, \Exception $e)
    {
        error_log("An error occurred: " . $e->getMessage());
        // Optionally, send an error message to the client
        // $conn->send(json_encode(["type" => "error", "message" => $e->getMessage()]));
        // Close the connection if necessary
        // $conn->close();
    }

    private function loadMap($mapId)
    {
        try {
            $stmt = $this->db->prepare("SELECT data FROM maps WHERE id = :mapId");
            $stmt->bindValue(":mapId", $mapId, \PDO::PARAM_INT);
            $stmt->execute();

            if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                return json_decode($row["data"], true);
            }
        } catch (\Exception $e) {
            error_log("Error loading map: " . $e->getMessage());
        }

        return null;
    }

    private function updatePlayerCharacterIndex($playerId, $characterIndex)
    {
        if (isset($this->players[$playerId])) {
            try {
                // Update the player's character_index
                $this->players[$playerId]["character_index"] = $characterIndex;

                // Update the player's character_index in the database
                $stmt = $this->db->prepare(
                    "UPDATE players SET character_index = :character_index WHERE id = :id"
                );
                $stmt->bindValue(
                    ":character_index",
                    $characterIndex,
                    \PDO::PARAM_STR
                );
                $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                $stmt->execute();

                // Broadcast the updated player positions to all clients
                $this->broadcastPlayerPositions(
                    $this->players[$playerId]["map_id"]
                );
            } catch (\Exception $e) {
                error_log("Error updating player character index: " . $e->getMessage());
            }
        }
    }

    public function registerPlayer(ConnectionInterface $conn, $playerId)
    {
        try {
            if (!isset($this->players[$playerId])) {
                $stmt = $this->db->prepare("SELECT * FROM players WHERE id = :id");
                $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                $stmt->execute();

                if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $this->players[$playerId] = [
                        "id" => $playerId,
                        "x" => $row["x"],
                        "y" => $row["y"],
                        "width" => 32, // Assuming fixed size, adjust as necessary
                        "height" => 32,
                        "character_index" => $row["character_index"],
                        "facing" => $row["facing"],
                        "frame" => $row["frame"],
                        "map_id" => $row["map_id"],
                        "status" => $row["status"],
                        "last_active" => time(),
                        "connection" => $conn,
                    ];

                    // Send initial player data and map data only for the current map
                    $mapData = $this->loadMap($row["map_id"]);
                    $playersData = $this->getPlayersData($row["map_id"]);

                    $conn->send(
                        json_encode([
                            "type" => "init",
                            "player" => $this->players[$playerId],
                            "mapData" => $mapData,
                            "players" => $playersData,
                            "map_id" => $row["map_id"],
                        ])
                    );
                } else {
                    // Create a new player without passing $characterIndex
                    $this->createNewPlayer($playerId, $conn);

                    // Send initial player data and map data only for the current map
                    $mapData = $this->loadMap(1);
                    $playersData = $this->getPlayersData(1);

                    $conn->send(
                        json_encode([
                            "type" => "init",
                            "player" => $this->players[$playerId],
                            "mapData" => $mapData,
                            "players" => $playersData,
                            "map_id" => 1,
                        ])
                    );
                }
                $this->addToMap($playerId, $this->players[$playerId]["map_id"]);
            }

            $this->broadcastPlayerPositions($this->players[$playerId]["map_id"]);
        } catch (\Exception $e) {
            $this->handleException($conn, $e);
        }
    }

    private function createNewPlayer(
        $playerId,
        ConnectionInterface $conn,
        $characterIndex = 0
    ) {
        try {
            $width = 32;
            $height = 32;
            $position = $this->findNonOverlappingPosition($width, $height);
            $initialFrame = 0; // Starting frame index for the animation
            $initialMapId = 1;

            $this->players[$playerId] = [
                "id" => $playerId,
                "x" => $position["x"],
                "y" => $position["y"],
                "width" => $width,
                "height" => $height,
                "character_index" => $characterIndex,
                "facing" => "down",
                "frame" => $initialFrame,
                "map_id" => $initialMapId,
                "status" => 1,
                "last_active" => time(),
                "connection" => $conn,
            ];

            // Insert the new player into the database
            $stmt = $this->db->prepare(
                "INSERT INTO players (id, x, y, width, height, character_index, facing, frame, map_id, status, last_active) VALUES (:id, :x, :y, :width, :height, :character_index, :facing, :frame, :map_id, :status, :last_active)"
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
        } catch (\Exception $e) {
            error_log("Error creating new player: " . $e->getMessage());
        }
    }

    private function broadcastPlayerLeaveMap($playerId, $mapId)
    {
        $payload = json_encode([
            "type" => "playerLeaveMap",
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
                            error_log("Error broadcasting player leave map: " . $e->getMessage());
                        }
                    }
                }
            }
        }
    }

    private function movePlayer($playerId, $newX, $newY, $facing, $frame = 0)
    {
        if (isset($this->players[$playerId])) {
            $player = &$this->players[$playerId];

            $currentTime = time();
            $player["last_active"] = $currentTime;

            // Update the player's status if it was previously inactive
            if ($player["status"] == 0) {
                $player["status"] = 1;
                try {
                    $stmt = $this->db->prepare(
                        "UPDATE players SET status = 1, last_active = :last_active WHERE id = :id"
                    );
                    $stmt->bindValue(":last_active", $currentTime, \PDO::PARAM_INT);
                    $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                    $stmt->execute();
                } catch (\Exception $e) {
                    error_log("Error updating player status: " . $e->getMessage());
                }
            }

            // Check for map transitions
            $transitionData = $this->checkMapTransitions($player, $newX, $newY);

            if ($transitionData !== null) {
                $player["connection"]->send(
                    json_encode([
                        "type" => "enteringNewMap",
                    ])
                );

                $oldMapId = $player["map_id"];
                $player["map_id"] = $transitionData["map_id"];
                $player["x"] = $transitionData["x"];
                $player["y"] = $transitionData["y"];

                // Update the player's map_id in the database
                try {
                    $stmt = $this->db->prepare(
                        "UPDATE players SET map_id = :map_id, x = :x, y = :y WHERE id = :id"
                    );
                    $stmt->bindValue(":map_id", $player["map_id"], \PDO::PARAM_INT);
                    $stmt->bindValue(":x", $player["x"], \PDO::PARAM_INT);
                    $stmt->bindValue(":y", $player["y"], \PDO::PARAM_INT);
                    $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                    $stmt->execute();
                } catch (\Exception $e) {
                    error_log("Error updating player map_id: " . $e->getMessage());
                }

                // Remove player from old map and add to new map
                $this->removeFromMap($playerId, $oldMapId);
                $this->addToMap($playerId, $player["map_id"]);

                // Get the list of other players on the new map
                $otherPlayers = $this->getPlayersData($player["map_id"]);
                unset($otherPlayers[$playerId]); // Exclude the transitioning player from the list

                // Send updated map data and other players' data to the transitioning player
                $mapData = $this->loadMap($player["map_id"]);
                if ($mapData !== null) {
                    $player["connection"]->send(
                        json_encode([
                            "type" => "mapData",
                            "data" => $mapData,
                            "map_id" => $player["map_id"],
                            "id" => $playerId,
                            "x" => $player["x"],
                            "y" => $player["y"],
                            "players" => $otherPlayers,
                        ])
                    );
                } else {
                    // Handle the case when map data is null
                    error_log(
                        "Map data is null for map ID: " . $player["map_id"]
                    );
                }

                // Send message to all players on the old map that the player has left
                $this->broadcastPlayerLeaveMap($playerId, $oldMapId);

                // Add the player to the list of moved players
                $this->movedPlayers[$playerId] = true;
            } else {
                // Handle non-transition movements
                $this->handleStandardMovement(
                    $playerId,
                    $newX,
                    $newY,
                    $facing,
                    $frame
                );

                // Update the player's last_active time in the database
                try {
                    $stmt = $this->db->prepare(
                        "UPDATE players SET last_active = :last_active WHERE id = :id"
                    );
                    $stmt->bindValue(":last_active", $currentTime, \PDO::PARAM_INT);
                    $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                    $stmt->execute();
                } catch (\Exception $e) {
                    error_log("Error updating player last_active: " . $e->getMessage());
                }

                // Add the player to the list of moved players
                $this->movedPlayers[$playerId] = true;
            }

            // Check if enough time has elapsed since the last broadcast
            $currentTimeMicro = microtime(true);
            if ($currentTimeMicro - $this->lastBroadcastTime >= 0.05) {
                // Adjust the interval as needed
                $this->broadcastMovedPlayerPositions();
                $this->lastBroadcastTime = $currentTimeMicro;
            }
        }
    }

    private function updateInactivePlayers()
    {
        $inactiveTime = 300; // 5 minutes in seconds

        foreach ($this->players as $playerId => $player) {
            if (
                $player["status"] == 1 &&
                time() - $player["last_active"] >= $inactiveTime
            ) {
                $player["status"] = 0;
                try {
                    $stmt = $this->db->prepare(
                        "UPDATE players SET status = 0 WHERE id = :id"
                    );
                    $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                    $stmt->execute();
                } catch (\Exception $e) {
                    error_log("Error updating inactive player status: " . $e->getMessage());
                }
            }
        }
    }

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

    private function handleStandardMovement(
        $playerId,
        $newX,
        $newY,
        $facing,
        $frame
    ) {
        $player = &$this->players[$playerId];
        // Check for collisions with other players
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

        // Check for collisions with non-walkable tiles
        if (
            !$collision &&
            $this->isCollidingWithMap(
                $newX,
                $newY,
                $player["width"],
                $player["height"],
                $player["map_id"]
            )
        ) {
            $collision = true;
        }

        if (!$collision) {
            // Update the player's position and facing direction
            $player["x"] = $newX;
            $player["y"] = $newY;
            $player["facing"] = $facing;

            // Update the player's frame if provided, otherwise set it to 0
            $player["frame"] = $frame ?? 0;

            // Perform the database update
            try {
                $stmt = $this->db->prepare(
                    "UPDATE players SET x = :x, y = :y, facing = :facing, frame = :frame WHERE id = :id"
                );
                $stmt->bindValue(":x", $newX, \PDO::PARAM_INT);
                $stmt->bindValue(":y", $newY, \PDO::PARAM_INT);
                $stmt->bindValue(":facing", $facing, \PDO::PARAM_STR);
                $stmt->bindValue(":frame", $player["frame"], \PDO::PARAM_INT);
                $stmt->bindValue(":id", $playerId, \PDO::PARAM_STR);
                $stmt->execute();
            } catch (\Exception $e) {
                error_log("Error updating player position: " . $e->getMessage());
            }

            // Broadcast the updated player position to all clients
            $this->broadcastPlayerPosition($playerId);
        }
    }

    private function checkMapTransitions($player, $newX, $newY)
    {
        $currentMapId = $player["map_id"];
        $tileSize = self::TILE_SIZE;
        $mapWidth = self::MAP_WIDTH;
        $mapHeight = self::MAP_HEIGHT;

        if ($newX < 0) {
            return $this->handleTransition(
                $currentMapId,
                "map_left",
                ($mapWidth - 1) * $tileSize - $player["width"],
                $newY
            );
        } elseif ($newX + $player["width"] > $mapWidth * $tileSize) {
            return $this->handleTransition(
                $currentMapId,
                "map_right",
                0,
                $newY
            );
        }

        if ($newY < 0) {
            return $this->handleTransition(
                $currentMapId,
                "map_top",
                $newX,
                ($mapHeight - 1) * $tileSize - $player["height"]
            );
        } elseif ($newY + $player["height"] > $mapHeight * $tileSize) {
            return $this->handleTransition(
                $currentMapId,
                "map_bottom",
                $newX,
                0
            );
        }

        return null;
    }

    private function handleTransition($currentMapId, $directionField, $x, $y)
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT $directionField FROM maps WHERE id = ?"
            );
            $stmt->execute([$currentMapId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($result && $result[$directionField]) {
                return [
                    "map_id" => $result[$directionField],
                    "x" => $x,
                    "y" => $y,
                ];
            }
        } catch (\Exception $e) {
            error_log("Error handling map transition: " . $e->getMessage());
        }
        return null;
    }

    private function isCollidingWithMap($x, $y, $width, $height, $mapId)
    {
        $tileSize = self::TILE_SIZE;
        $mapWidth = self::MAP_WIDTH;
        $mapHeight = self::MAP_HEIGHT;

        // Calculate all corners of the new position
        $leftX = floor($x / $tileSize);
        $rightX = floor(($x + $width - 1) / $tileSize);
        $topY = floor($y / $tileSize);
        $bottomY = floor(($y + $height - 1) / $tileSize);

        // Check collision with the physical boundaries of the map
        if (
            $x < 0 ||
            $x + $width > $mapWidth * $tileSize ||
            $y < 0 ||
            $y + $height > $mapHeight * $tileSize
        ) {
            return true;
        }

        // Load map data
        $tileData = $this->loadMap($mapId);
        if ($tileData === null) {
            error_log("Error: Map data is null for map ID $mapId");
            return true;
        }

        // Check for collision with any non-walkable tile in the area covered
        for ($row = $topY; $row <= $bottomY; $row++) {
            for ($col = $leftX; $col <= $rightX; $col++) {
                if (
                    $row < 0 ||
                    $row >= $mapHeight ||
                    $col < 0 ||
                    $col >= $mapWidth
                ) {
                    continue; // Skip checking out of bounds indices
                }
                if (!isset($tileData[$row][$col])) {
                    continue;
                }
                if (
                    !$this->isWalkable(
                        $tileData[$row][$col][0],
                        $tileData[$row][$col][1]
                    )
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isWalkable($tileRow, $tileCol)
    {
        foreach ($this->walkableTiles as $tile) {
            if ($tileRow === $tile[0] && $tileCol === $tile[1]) {
                return true;
            }
        }
        return false;
    }

    private function findNonOverlappingPosition($width, $height)
    {
        $mapWidth = self::MAP_WIDTH * self::TILE_SIZE; // Total pixels in map width
        $mapHeight = self::MAP_HEIGHT * self::TILE_SIZE; // Total pixels in map height
        $maxAttempts = 100;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            // Calculate coordinates close to the center but randomize within a range to avoid stacking
            $x = rand($mapWidth / 4, ($mapWidth * 3) / 4 - $width);
            $y = rand($mapHeight / 4, ($mapHeight * 3) / 4 - $height);

            $overlaps = false;

            // Check if the position overlaps with any existing player
            foreach ($this->players as $player) {
                if ($player["status"] == 1) {
                    if (
                        $this->isColliding(
                            $x,
                            $y,
                            $width,
                            $height,
                            $player["x"],
                            $player["y"],
                            $player["width"],
                            $player["height"]
                        )
                    ) {
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

        // If no suitable position found after max attempts, default to center of the map
        return [
            "x" => ($mapWidth - $width) / 2,
            "y" => ($mapHeight - $height) / 2,
        ];
    }

    private function isColliding(
        $x1,
        $y1,
        $width1,
        $height1,
        $x2,
        $y2,
        $width2,
        $height2
    ) {
        return $x1 < $x2 + $width2 &&
            $x1 + $width1 > $x2 &&
            $y1 < $y2 + $height2 &&
            $y1 + $height1 > $y2;
    }

    private function getPlayersData($mapId)
    {
        $playersData = [];

        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM players WHERE map_id = :mapId AND status = 1"
            );
            $stmt->bindValue(":mapId", $mapId, \PDO::PARAM_INT);
            $stmt->execute();

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $playersData[$row["id"]] = [
                    "x" => $row["x"],
                    "y" => $row["y"],
                    "character_index" => $row["character_index"],
                    "facing" => $row["facing"],
                    "frame" => $row["frame"],
                    "map_id" => $row["map_id"],
                    "status" => $row["status"],
                ];
            }
        } catch (\Exception $e) {
            error_log("Error getting players data: " . $e->getMessage());
        }

        return $playersData;
    }

    private function broadcastPlayerPositions($mapId)
    {
        $playersData = $this->getPlayersData($mapId);
        $payload = json_encode([
            "type" => "update",
            "players" => $playersData,
            "mapId" => $mapId,
        ]);

        if (isset($this->mapPlayers[$mapId])) {
            foreach ($this->mapPlayers[$mapId] as $id) {
                if (isset($this->players[$id])) {
                    $conn = $this->players[$id]["connection"];
                    if ($conn !== null) {
                        try {
                            $conn->send($payload);
                        } catch (\Exception $e) {
                            error_log("Error broadcasting player positions: " . $e->getMessage());
                        }
                    }
                }
            }
        }
    }

    private function broadcastPlayerPosition($playerId)
    {
        if (!isset($this->players[$playerId])) {
            // Log or handle the error: Player not found
            error_log("Error broadcasting player position: Player ID $playerId not found.");
            return;
        }

        $player = $this->players[$playerId];
        if ($player["connection"] === null) {
            // Connection is null, handle the case, perhaps reinitialize or log error
            error_log("Error broadcasting player position: Connection is null for Player ID $playerId.");
            return;
        }

        $mapId = $player["map_id"];
        $payload = json_encode([
            "type" => "playerUpdate",
            "player" => [
                "id" => $player["id"],
                "x" => $player["x"],
                "y" => $player["y"],
                "character_index" => $player["character_index"],
                "facing" => $player["facing"],
                "frame" => $player["frame"],
                "map_id" => $player["map_id"],
                "width" => $player["width"],
                "height" => $player["height"],
                "status" => $player["status"],
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
                            error_log("Error broadcasting player position: " . $e->getMessage());
                        }
                    }
                }
            }
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
        error_log("An error has occurred: {$e->getMessage()}");
        $conn->close();
    }
}
