# Traivan Map Analyzer Demo Project

**Complexity: 4/5**

Demo project demonstrating multi-threaded processing of large SQL dumps with real-time analysis.

## What is this?

This project reads and analyzes `map.sql` files from the Traivan game from different domains in parallel threads.

### Features

- **Multi-threaded processing**: One thread scans and finds new domains for reading map.sql files
- **Parallel parsing**: Multiple threads read and parse map structure (locations, objects, connections) in parallel
- **Event processing**: Separate threads process events and build map graphs
- **AI analysis**: AI agent generates analytical reports based on collected data
- **Real-time visualization**: Frontend on Vue 3 + TypeScript visualizes maps as interactive graphs
- **Interactive features**: Path finding between locations, filtering by object types, statistics display, zoom and pan

### Technical Highlights

- File system operations
- SQL parsing in multi-threaded mode
- Graph algorithms
- Caching large data structures in memory
- Real-time visualization through WebSocket
- AI analysis and report generation on map structure

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
