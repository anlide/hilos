# Mail Monitor Demo Project

**Complexity: 4/5**

Demo project demonstrating mail server monitoring system (Postfix + Dovecot).

## What is this?

Daemon connects to local Postfix and Dovecot server. Analyzes mail server logs in real-time. Tracks email sending and receiving, errors, statistics.

### Features

- **Multi-threaded log reading**: Multiple threads read Postfix and Dovecot logs in parallel
- **Log analysis**: Separate threads analyze logs, extract metrics, process events
- **AI analysis**: AI analyzes log patterns, detects anomalies, spam attacks
- **Real-time status**: Number of sent/received emails, email queue, delivery errors, blocked emails, domain statistics
- **Queue monitoring**: Real-time email queue monitoring, track status of each email (sent, in queue, error)
- **Delivery tracking**: Detailed information about delivery, delays, problems
- **Problem detection**: Analyze logs for problems - undelivered emails, authentication errors, spam attacks, DNS issues, IP blocks
- **Real-time statistics**: Charts of email sending/receiving over time, top senders/recipients, domain statistics, successful delivery percentage, errors by type
- **Alerts**: Alerts when problems detected - mass sending, suspicious activity, delivery errors, queue overflow, server problems
- **Frontend**: Vue 3 + TypeScript with monitoring dashboard, statistics charts, email queue list, real-time logs, alert system

### Technical Highlights

- Log file operations
- Mail traffic analysis
- Real-time monitoring
- Large log volume processing
- AI pattern analysis
- Alerting system

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
