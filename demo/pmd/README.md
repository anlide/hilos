# PMD Demo Project

**Complexity: 5/5**

Demo project demonstrating a web interface for database management with real-time updates.

## What is this?

PhpMyAdmin analog with WebSocket functionality. Manage MySQL/MariaDB databases through web interface. View tables, execute SQL queries, edit data.

### Features

- **Complex updatable tables**: Main feature and complexity - very complex updatable tables
- **Real-time updates**: Tables with many rows updated in real-time through WebSocket
- **Instant reflection**: Database changes immediately reflected in interface without reload
- **Advanced table features**: Support for complex tables with thousands of rows, pagination, sorting, filtering
- **Row-level updates**: Real-time updates of individual rows when data changes in database
- **Optimistic UI updates**: Optimistic UI updates with state synchronization
- **SQL execution**: Execute SQL queries with real-time result display
- **Multiple tabs**: Multiple tabs with different queries, synchronization between tabs
- **Query history**: Query history, save frequently used queries
- **Data editing**: Edit data in tables with validation and synchronization
- **Multi-user editing**: Changes by one user visible to others in real-time
- **Conflict prevention**: Prevent conflicts during simultaneous editing
- **Schema management**: Table structure, indexes, foreign keys - all updated in real-time
- **Multi-threaded processing**: Multiple threads track database changes, separate threads process SQL queries in parallel

### Technical Highlights

- Database operations
- Complex updatable tables
- Real-time data synchronization
- Performance optimization
- Large data volume processing
- Conflict prevention

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
