<?php
require_once __DIR__ . '/../src/bootstrap.php';
redirect(auth_user() ? auth_home_url() : base_url('login.php'));
