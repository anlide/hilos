# AI Bots Battle Demo Project

**Complexity: 4/5**

Demo project demonstrating a turn-based game where two AI bots fight continuously.

## What is this?

Space battle between two fleets. Simple rules: each ship has health, attack, defense. Bots take turns (once per minute or once per 10 minutes). Game runs continuously, even when there are no viewers.

### Features

- **AI bot control**: AI bots controlled through ChatGPT API - each bot receives current game state and makes decision about move
- **Bot commentary**: Bots can comment on their actions in chat
- **Player interaction**: Players can connect and watch battle in real-time, chat, discuss moves, place bets
- **AI reactions**: AI bots react to player comments in chat
- **Admin console**: Console for admin - start/stop game, configure battle parameters, view statistics
- **Frontend**: Vue 3 + TypeScript shows game field, current fleet state, move history, chat with bots and players

### Technical Highlights

- ChatGPT API integration
- Continuous agent operation
- Turn-based game logic
- AI reaction to chat
- Console management

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
