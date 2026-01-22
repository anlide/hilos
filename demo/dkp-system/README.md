# DKP System Demo Project

**Complexity: 3/5**

Demo project demonstrating DKP (Dragon Killing Points) system for managing a guild in an online game.

## What is this?

System for fair loot distribution in a guild of 150 people. Players go on raids, get loot and distribute it among themselves.

### Features

- **Player tracking**: Emulation of player login/logout in guild in real-time, activity tracking, online/offline status
- **Raid emulation**: Raids once per week for 2-3 hours, automatic raid creation on schedule, participation tracking, DKP accrual
- **Loot rolling system**: Players have roles (tank, healer, DPS, etc.) and priorities on certain item types
- **Fair distribution**: System determines who needs what by role and priority, fair distribution considering DKP, role, priorities
- **Real-time display**: Current raid, participants, loot obtained, who is rolling - all displayed in real-time
- **History and statistics**: Raid history, player statistics, DKP balance
- **Admin panel**: Manage guild, players, raids, raid schedule, player roles, DKP adjustment, item priorities
- **Frontend**: Vue 3 + TypeScript with current raid display, player list, rolling system, history, statistics

### Technical Highlights

- Work with large user groups
- Game process emulation
- Fair resource distribution
- Real-time state synchronization
- Priority and role system

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
