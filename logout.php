<?php
// ログアウト（処理のみ）→ SC-00
require_once __DIR__ . '/common/auth.php';

logout();
redirect('/index.php');
