-- hilos-framework: {"format":1,"kind":"baseline","units":["access_log","account_deletion","account_erasure_assignment","account_erasure_operation","account_erasure_result","admin_view_mode_latch","analytics","auth_block","data_export","database_marker","file","file_variant","identity","legal_acceptance","legal_acceptance_export","migration","migration_claim","moderation_rule","notification","notification_delivery","notification_preference","oauth_provider","passkey_credential","push_subscription","registration_reservation","second_factor","second_factor_backup_code","second_factor_reset","second_factor_setting","second_factor_trust","session","setting","step_up","user","user_merge","user_photo","user_rename","user_verification","verifier_circle"],"parts":[{"changeId":"20261021021039718057_HIL-1580_add_user_time_zone","unit":"user","upSha256":"b2647c3d0f088113ed205a20f02f096d6caf7f938f8186a9c880efab283d7acd","downSha256":"96b6ce1980bce0d15f2e112b33ffc07be131c30ade9fd9af8cbc9e1f9daae868"},{"changeId":"20261022124515804554_HIL-1582_browser_time_zones","unit":"session","upSha256":"a4d49cd6df1b07709373e358c6187bd8f61abbfa8dd254cd5dec7e9fb6353e08","downSha256":"5463fc2cadbf87264a2b701cb3f3662086c3c474a99d56e9f4ccfab678d02932"},{"changeId":"20261022124515804554_HIL-1582_browser_time_zones","unit":"user","upSha256":"67089551c34a8123a79671d2d6ccb4bd297495ae8b982360d61b7753e99b9caf","downSha256":"d6335c7340bad11a0ae80077d00c6e5e2501ed5135d90eb037133bc11ba20bb8"},{"changeId":"20261022205657000000_HIL-1624_add_moderation_rules","unit":"moderation_rule","upSha256":"de30787b88b5e0f945159688f8427e7eb278a2c77a0477c9ff40a0eae303064f","downSha256":"62a45b5fb2c723f7d5126e3eeb0df8871ed775735829fe3f1b7ab0630e7d0379"},{"changeId":"20261023065022000000_HIL-1653_identity_display_fields","unit":"identity","upSha256":"48d533d4b3de2d97b0e816b80a988115463d0aa9f51a136247a59a34a836bcbb","downSha256":"2f695e56ce625ca6ee9def7ff38f2010ff025d293953937330338ff9fb95f0c6"},{"changeId":"20261028002725141545_HIL-1691_status_subscribers","unit":"auth_block","upSha256":"019bdcc4818f10727ceb1242a43fedbe496ea4fe7868b53d5a25620f5515c856","downSha256":"038296396eb4c787eba64f3ece8467755847990404fbb9f8a149d86bb65a58dc"},{"changeId":"20261101093551800031_HIL-1579_export_time_zones","unit":"data_export","upSha256":"be943f41a975ec3749a48a63b8698127f487018e41b4e6a94ebc5bb46e536256","downSha256":"b6e78df3dc546fc3a579ef020875276fbeac183d6601ac8f91b4dddefc0059fe"},{"changeId":"20261101093551800031_HIL-1579_export_time_zones","unit":"legal_acceptance_export","upSha256":"7a2ea42839a0d86fde8699fbca3279b242b0614715c84618559967ea357bc671","downSha256":"c49818c4e69ea4cd01bb33ecf2174432faecdbfc08df9f71a9c49959eb37c9bd"},{"changeId":"20261108074007375591_HIL-1936_account_erasure_storage","unit":"account_erasure_assignment","upSha256":"702aa9b50719af69a643f586381a3cbcd5259f67651e031fdbca51dae60b128d","downSha256":"1df6ee8e6e008d2b02a7986b9331660e38af37087d8d58bb76ce69b4dde089e1"},{"changeId":"20261108074007375591_HIL-1936_account_erasure_storage","unit":"account_erasure_operation","upSha256":"60b3f37006521890d48915e6211602a178934d023758e4151c9dbf0a9628c6f6","downSha256":"ff3dbd084beab6ab7b7eb9d7d3a5fed4b8e7cfc163e2c245af16b740874018e4"},{"changeId":"20261108080825608007_HIL-1893_delivery_routing_key","unit":"notification_delivery","upSha256":"1d5d491a3adf33a6f309ff53d50fc29415e25b3b6769db0ee1eb2741a36bb10f","downSha256":"9d5762c5df5d80009efb79a1a90b3cb3fb63305a80cb11ea9c2f0dc85d600209"},{"changeId":"20261108210743046351_HIL-1937_account_erasure_results","unit":"account_erasure_result","upSha256":"da747c9bec6a3cfdfe2975db343d27f129d155f739b0c0311fb087044a2e740c","downSha256":"733a7d68e9fafbbd003ebb25cf3cceb821380b42eeee52a5563df70558338f13"},{"changeId":"20261109000848893922_HIL-1918_bootstrap_moments","unit":"admin_view_mode_latch","upSha256":"36835d946746adbefe4bf10afa273dd0bbcaecc605ab9006f7d4748d846372a8","downSha256":"58e821093cc581a3429d7cb24678a6befa452f04e0e4767dfda442e919d4553a"},{"changeId":"20261109000848893922_HIL-1918_bootstrap_moments","unit":"database_marker","upSha256":"43cd04116985ea537a7b98dffac6da618bd4a2bc7798162db3a0b5284fb8d2ed","downSha256":"aecb2b9f635b78da7bfb11b3b4d327d966329305a057673a6ce5c3076f2f2a3c"},{"changeId":"20261109000848893922_HIL-1918_bootstrap_moments","unit":"migration_claim","upSha256":"bf59cfc98e417a4453dcd259af3fea282eb97fcf54352a9c640a2fe49fc53a81","downSha256":"2112580d6bcc23ac509594e03d79fe503768bc96978a18a4402adf253e03d146"},{"changeId":"20261109072435317521_HIL-1987_account_operation_tables","unit":"account_erasure_assignment","upSha256":"fe67ccd3c07a0a5672487f803cc9eac53a9af1fcd832d52112baa14ef612e65a","downSha256":"dd8dbcc1eaab1fe1dd3226b5679d59e89b38023df513778531e6074072fe3e4f"},{"changeId":"20261109072435317521_HIL-1987_account_operation_tables","unit":"account_erasure_operation","upSha256":"b45d411a90362b9cba870265e8071e4870dc3c69ff22ea7df450bedb44f1beba","downSha256":"76829ea1906bd7a43c979d586f230a1bdc15c7a6aa697a7c42dc1e659efb1a80"},{"changeId":"20261109072435317521_HIL-1987_account_operation_tables","unit":"account_erasure_result","upSha256":"1702548fe5a976c44f43f905176038ff7518d6573ed6e568b71d7bbfee518a7f","downSha256":"59a89ba5fe898aef7344c47b7e73dda637cb5639ce0240adc42d20ede71fe08f"},{"changeId":"20261109072435317522_HIL-1987_drop_account_erasure_tables","unit":"account_erasure_assignment","upSha256":"1df6ee8e6e008d2b02a7986b9331660e38af37087d8d58bb76ce69b4dde089e1","downSha256":"702aa9b50719af69a643f586381a3cbcd5259f67651e031fdbca51dae60b128d"},{"changeId":"20261109072435317522_HIL-1987_drop_account_erasure_tables","unit":"account_erasure_operation","upSha256":"ff3dbd084beab6ab7b7eb9d7d3a5fed4b8e7cfc163e2c245af16b740874018e4","downSha256":"60b3f37006521890d48915e6211602a178934d023758e4151c9dbf0a9628c6f6"},{"changeId":"20261109072435317522_HIL-1987_drop_account_erasure_tables","unit":"account_erasure_result","upSha256":"733a7d68e9fafbbd003ebb25cf3cceb821380b42eeee52a5563df70558338f13","downSha256":"da747c9bec6a3cfdfe2975db343d27f129d155f739b0c0311fb087044a2e740c"},{"changeId":"20261109114206000000_HIL-1926_file_moments","unit":"file","upSha256":"9bf624040b50bf6233db92de60e9450fff4d3012809bc7af0535365e7aa47884","downSha256":"f43f297700aacb6d3eecdb25b6b973b523abece0b1672f6683bacf88d65b6c4f"},{"changeId":"20261109114206000000_HIL-1926_file_moments","unit":"file_variant","upSha256":"6a9e036d65400a184e17422444eb66542c88e05d06e5557757817a124af55168","downSha256":"a9b2417d0380636576e59db48eea52daeedb8c97e551ff96dd8df78d94341ba1"},{"changeId":"20261109120150360881_HIL-1919_session_moments","unit":"session","upSha256":"845bad63c380800b8ec9f5f040c272ea1ff3c5de01882e595dc3be5e4b7010e9","downSha256":"269b2b5f7552d3d6087a6e2e4ad1dfe2124a8833fe96f7550412f40b2d635af3"},{"changeId":"20261109120150360881_HIL-1919_session_moments","unit":"verifier_circle","upSha256":"e7b4ac3d68e1df08d69b52033e40e90c1176bb075f89d405a9a8af57dbee813c","downSha256":"12d25906c8344f6105a6c17523914829f2c0754f077e77e9ede1e36cf67dcd32"},{"changeId":"20261109185924000000_HIL-1927_analytics_signed_moments","unit":"analytics","upSha256":"d9291f0a293719ae43fe61cd7c961a1723875ceb8931761fee3bd5a7766b9589","downSha256":"33559325fb8728e58d859f9cf14d5637d04b38b8fd5af81f35e449bdf5689ebe"},{"changeId":"20261110021316313226_HIL-1923_notification_moments","unit":"notification","upSha256":"94aaf870c0113632ef7144c262a3334193f8d035302c6130ce7212f8bed623cb","downSha256":"1f369a7c5b2e94da1077a1e81c6ad0f30d886b2248ffde8783010254cbd36b2d"},{"changeId":"20261110021316313226_HIL-1923_notification_moments","unit":"notification_delivery","upSha256":"d90be61266beaf94a8f40d26fe24f9bf74cb4447767ae479341fdfdddcde63ce","downSha256":"81de6e9abb90f1925e245a108faa0a70dee71ed054a1a38f289122ea3a0f0168"},{"changeId":"20261110021316313226_HIL-1923_notification_moments","unit":"notification_preference","upSha256":"f0d5f57356351a7dea605266eda168f34754ea2624690789ddba05cdcb319974","downSha256":"559fd3c422b7e8ba1dfbd1b8e431f0a4de81a38a1e36496b7ed7b0a96d44cc4a"},{"changeId":"20261110021316313226_HIL-1923_notification_moments","unit":"push_subscription","upSha256":"1e00260fdf68931f378d121f6c9e170fd8b75c3724bf2ae7e2eba1d5e2c75341","downSha256":"403afc4e828acbbaa47e194322c201b2af2c20703fc4e030fa0d9a6407de397f"}],"upSha256":"a72cd11ce971d004018578d8657355539952ee6c9f8094ac7780d1145318f8c0","downSha256":"cafd7744777e34b5457345d97e5df76834497895ef332f426e565dbe641118c4"}
-- Migration Rollback: Drop Hilos analytics tables (stub/reference)

DROP TABLE IF EXISTS `hilos_analytics_loss`;
DROP TABLE IF EXISTS `hilos_analytics_person_event`;
DROP TABLE IF EXISTS `hilos_analytics_journal_file`;
DROP TABLE IF EXISTS `hilos_analytics_api_agent_action`;
DROP TABLE IF EXISTS `hilos_analytics_api_request`;
DROP TABLE IF EXISTS `hilos_analytics_worker_system_signal`;
DROP TABLE IF EXISTS `hilos_analytics_agent_cron_signal`;
DROP TABLE IF EXISTS `hilos_analytics_agent_system_signal`;
DROP TABLE IF EXISTS `hilos_analytics_agent_user_action`;
DROP TABLE IF EXISTS `hilos_analytics_user_action`;
DROP TABLE IF EXISTS `hilos_analytics_agent_session`;
DROP TABLE IF EXISTS `hilos_analytics_worker_session`;
DROP TABLE IF EXISTS `hilos_analytics_page_session`;
DROP TABLE IF EXISTS `hilos_analytics_ws_connection_ipv6_change`;
DROP TABLE IF EXISTS `hilos_analytics_ws_connection_ipv4_change`;
DROP TABLE IF EXISTS `hilos_analytics_ws_connection`;
DROP TABLE IF EXISTS `hilos_analytics_browser_session_accept_language_change`;
DROP TABLE IF EXISTS `hilos_analytics_browser_session_user_agent_change`;
DROP TABLE IF EXISTS `hilos_analytics_browser_session`;
DROP TABLE IF EXISTS `hilos_analytics_browser_session_alias`;
DROP TABLE IF EXISTS `hilos_analytics_payload_json`;
DROP TABLE IF EXISTS `hilos_analytics_cron_name`;
DROP TABLE IF EXISTS `hilos_analytics_signal_name`;
DROP TABLE IF EXISTS `hilos_analytics_action_name`;
DROP TABLE IF EXISTS `hilos_analytics_page_params`;
DROP TABLE IF EXISTS `hilos_analytics_page`;
DROP TABLE IF EXISTS `hilos_analytics_accept_language`;
DROP TABLE IF EXISTS `hilos_analytics_user_agent`;

DROP TABLE IF EXISTS `hilos_moderation_rule`;

-- Rollback: Drop the framework profile photo table.
DROP TABLE IF EXISTS `hilos_user_photo`;

DROP TABLE IF EXISTS `hilos_file_variant`;

-- Migration down: Drop hilos_file table (stub/reference)

DROP TABLE IF EXISTS `hilos_file`;

-- Migration down: Drop hilos_notification_delivery table (stub/reference)

DROP TABLE IF EXISTS `hilos_notification_delivery`;

-- Migration down: Drop hilos_push_subscription table (stub/reference)

DROP TABLE IF EXISTS `hilos_push_subscription`;

-- Migration down: Drop hilos_notification_preference table (stub/reference)

DROP TABLE IF EXISTS `hilos_notification_preference`;

-- Migration down: Drop hilos_notification table (stub/reference)

DROP TABLE IF EXISTS `hilos_notification`;

-- Migration down: Drop hilos_auth_block table (stub/reference)

DROP TABLE IF EXISTS `hilos_auth_block`;

DROP TABLE IF EXISTS `hilos_legal_acceptance_export`;

-- Migration down: Drop hilos_oauth_provider table (stub/reference)

DROP TABLE IF EXISTS `hilos_oauth_provider`;

-- Rollback: Drop the framework merge table.
DROP TABLE IF EXISTS `hilos_user_merge`;

-- Rollback: Drop the framework rename journal.
DROP TABLE IF EXISTS `hilos_user_rename`;

DROP TABLE IF EXISTS `hilos_data_export`;

-- Rollback: Drop immutable legal acceptances (HIL-498).
DROP TABLE IF EXISTS `hilos_legal_acceptance`;

DROP TABLE IF EXISTS `hilos_account_operation_result`;

DROP TABLE IF EXISTS `hilos_account_operation_assignment`;

DROP TABLE IF EXISTS `hilos_account_operation`;

-- Migration down: Drop hilos_account_deletion table (stub/reference)

DROP TABLE IF EXISTS `hilos_account_deletion`;

-- Migration down: Drop hilos_access_log table (stub/reference)

DROP TABLE IF EXISTS `hilos_access_log`;

-- Migration down: Drop hilos_step_up table (stub/reference)

DROP TABLE IF EXISTS `hilos_step_up`;

-- Migration down: Drop hilos_second_factor_setting table (stub/reference)

DROP TABLE IF EXISTS `hilos_second_factor_setting`;

-- Migration down: Drop hilos_second_factor_reset table (stub/reference)

DROP TABLE IF EXISTS `hilos_second_factor_reset`;

-- Migration down: Drop hilos_second_factor_trust table (stub/reference)

DROP TABLE IF EXISTS `hilos_second_factor_trust`;

-- Migration down: Drop hilos_second_factor_backup_code table (stub/reference)

DROP TABLE IF EXISTS `hilos_second_factor_backup_code`;

-- Migration down: Drop hilos_second_factor table (stub/reference)

DROP TABLE IF EXISTS `hilos_second_factor`;

-- Migration down: Drop hilos_registration_reservation table (stub/reference)

DROP TABLE IF EXISTS `hilos_registration_reservation`;

-- Migration down: Drop hilos_passkey_credential table (stub/reference)

DROP TABLE IF EXISTS `hilos_passkey_credential`;

-- Migration down: Drop hilos_user_verification table (stub/reference)

DROP TABLE IF EXISTS `hilos_user_verification`;

-- Migration down: Drop hilos_identity table (stub/reference)

DROP TABLE IF EXISTS `hilos_identity`;

-- Migration Rollback: Create hilos_setting table (stub/reference)

DROP TABLE IF EXISTS `hilos_setting`;

-- Migration down: Drop hilos_verifier_circle table (stub/reference)

DROP TABLE IF EXISTS `hilos_verifier_circle`;

-- Migration (down): Drop hilos_session table (stub/reference)

DROP TABLE IF EXISTS `hilos_session`;

-- Rollback: Drop framework people.
DROP TABLE IF EXISTS `hilos_user`;
