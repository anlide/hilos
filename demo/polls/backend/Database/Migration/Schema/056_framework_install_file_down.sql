-- hilos-framework: {"format":1,"kind":"install","units":["file"],"parts":[],"upSha256":"9235fbfc7ec909dae6bfb6570b5c0081c2fad554f7aa77f7453f8c9d41b75d68","downSha256":"f2a940077724421dee78ff78528db41420284ce04e1e8019ca3648f2e327dc99"}
-- Migration down: Drop hilos_file table (stub/reference)

DROP TABLE IF EXISTS `hilos_file`;
