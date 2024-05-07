# XVEN (eXtensible Virtual ENvironment)

XVEN is a platform for interactive fiction where the community shapes the narrative and mechanics through extensive modularity and personalization.

## Vision

- **AI-Driven Gameplay:** Sophisticated AI characters offer unique challenges and interactions.
- **Player Interactions:** Collaborative quests and shared exploration enhance community engagement.
- **Extensible Design:** Modular architecture allows users to extend and modify the game dynamically.
- **Open Source & Free:** Accessible to everyone, promoting a culture of sharing and innovation.

## Current Multiplayer Online Game Features

- **WebSocket-Based Multiplayer:** The game uses WebSockets to maintain a real-time connection between the server and clients, allowing players to interact and see each other within the same game world.

- **Player Movement:** Players can move their character using arrow keys or on-screen buttons. Their facing direction and animation frames are updated accordingly.

- **Map Data Loading:** The game retrieves map data from the server, organized into a grid layout, containing different tile types to represent walkable and non-walkable areas.

- **Player Registration and Tracking:** Each player receives a unique ID. The server tracks their position, character index, facing direction, and animation frame. New players' data is broadcasted to all connected clients.

- **Character Selection:** Players can choose their character sprite from multiple options. The selection is saved locally and sent to the server for synchronization.

- **Collision Detection:** The game prevents characters from passing through non-walkable tiles or overlapping with other players.

- **Map Transitions:** When reaching the map's edge, players transition to a new map using server-provided transition data.

- **Client-Server Communication:** Various message types enable player registration, movement updates, character selection, map data retrieval, and player position synchronization.

- **Player Interpolation and Extrapolation:** The game smooths out latency by interpolating and extrapolating player movements.

- **Responsive Design:** The game canvas adjusts to different screen resolutions and aspect ratios for a consistent experience.

- **Connection Handling:** WebSocket connection establishment, reconnection attempts, and closed connections are all handled gracefully.

- **Loading Screen:** A loading screen is displayed to ensure smooth asset loading.

## Requirements

- PHP ^8.1
- MySQL

## Installation

Follow these steps to set up the game server:

1. Run `composer install` to install Ratchet and other dependencies.
   
2. Create a MySQL database and import the `database.sql` file to set up the required tables.

3. Update the `Game.php` class to connect to your MySQL database with the appropriate credentials.

4. Start the server by running `php server.php` in your command line.

5. Update the `game.js` file to connect to your server instance. Make sure the WebSocket URL matches your server configuration.

## Additional Information

Ensure your PHP and MySQL environments are set up correctly before beginning the installation. Check your PHP version by running `php -v` in your terminal. If you encounter any issues, verify your installation steps and connection parameters.


Created by [Lance](https://lance.name).
