<?php
require_once __DIR__ . '/../src/bootstrap.php';
session_destroy(); redirect(base_url('login.php'));
