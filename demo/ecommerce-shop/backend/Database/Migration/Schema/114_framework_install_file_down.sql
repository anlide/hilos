-- hilos-framework: {"format":1,"kind":"install","units":["file"],"parts":[{"changeId":"20261109114206000000_HIL-1926_file_moments","unit":"file","upSha256":"9bf624040b50bf6233db92de60e9450fff4d3012809bc7af0535365e7aa47884","downSha256":"f43f297700aacb6d3eecdb25b6b973b523abece0b1672f6683bacf88d65b6c4f"}],"upSha256":"b68779902d213e120e5372900505f0c3d53705c1690c74471196cca2afd0230d","downSha256":"f2a940077724421dee78ff78528db41420284ce04e1e8019ca3648f2e327dc99"}
-- Migration down: Drop hilos_file table (stub/reference)

DROP TABLE IF EXISTS `hilos_file`;
