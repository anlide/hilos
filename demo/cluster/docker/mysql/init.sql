-- First-boot database + grants for the cluster demo.
--
-- Every cluster node uses its OWN schema on this one MariaDB, so concurrent first
-- boots never race on the settings-table migration. Coordination itself is never
-- in MySQL; these schemas only hold the framework settings table each node's
-- HilosDbContext registers.

CREATE DATABASE IF NOT EXISTS `hilos-demo-cluster-m1` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS `hilos-demo-cluster-m2` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS `hilos-demo-cluster-m3` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS `hilos-demo-cluster-s1` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS `hilos-demo-cluster-s2` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS `hilos-demo-cluster-cli` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

GRANT ALL PRIVILEGES ON `hilos-demo-cluster-m1`.* TO 'hilos'@'%';
GRANT ALL PRIVILEGES ON `hilos-demo-cluster-m2`.* TO 'hilos'@'%';
GRANT ALL PRIVILEGES ON `hilos-demo-cluster-m3`.* TO 'hilos'@'%';
GRANT ALL PRIVILEGES ON `hilos-demo-cluster-s1`.* TO 'hilos'@'%';
GRANT ALL PRIVILEGES ON `hilos-demo-cluster-s2`.* TO 'hilos'@'%';
GRANT ALL PRIVILEGES ON `hilos-demo-cluster-cli`.* TO 'hilos'@'%';
FLUSH PRIVILEGES;
