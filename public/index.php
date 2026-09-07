<?php
require_once __DIR__ . '/../src/bootstrap.php';
redirect(auth_user() ? base_url('dashboard.php') : base_url('login.php'));
