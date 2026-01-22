# Binance BTC Tracker Demo Project

**Complexity: 4/5**

Demo project demonstrating work with external APIs and real-time calculations in multi-threaded mode.

## What is this?

This project tracks Bitcoin prices from Binance and performs real-time calculations.

### Features

- **Multi-threaded API connections**: Multiple threads connect to Binance WebSocket API in parallel
- **Data processing**: Each thread reads data for different trading pairs or timeframes
- **Technical indicators**: Separate threads process indicators (SMA, EMA, RSI, MACD, Bollinger Bands) in real-time
- **Pattern detection**: Identifies patterns (support/resistance, trends)
- **Telegram integration**: Special thread processes data and sends notifications to Telegram bot
- **Interactive frontend**: Vue 3 + TypeScript shows interactive price charts with indicators, configurable alerts, signal history, volatility statistics, trading volumes

### Technical Highlights

- External WebSocket API integration
- Stream data processing
- Real-time mathematical calculations
- Telegram Bot API integration
- Historical data caching for fast access
- High-frequency update processing performance

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
