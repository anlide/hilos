# File Gallery Manager Demo Project

**Complexity: 3/5**

Demo project demonstrating file work: upload, download, image processing.

## What is this?

File manager with image gallery. Users upload files through web interface.

### Features

- **File processing**: Separate processing of downloadable files (documents, archives) and images (preview, processing, conversion)
- **Real-time image processing**: Resize, apply filters, format conversion (JPG, PNG, WebP), thumbnail generation
- **AI image moderation**: Checks for unwanted content, automatically removes or hides inappropriate images, generates descriptions
- **Private messaging**: Personal messages between users with file exchange in private chats
- **Multi-threaded processing**: Multiple threads process file uploads in parallel, separate threads process images, AI moderation works in separate thread
- **Frontend**: Vue 3 + TypeScript with drag-and-drop upload, gallery with preview, image processing tools, file download, private messaging system

### Technical Highlights

- File system operations
- File upload/download
- Image processing
- Thumbnail generation
- AI content moderation
- Real-time gallery updates
- Private messages with files

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
