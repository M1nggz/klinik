<?php
require __DIR__ . '/includes/boot.php';
if (me()) logout_user();
session_start(); flash('ok', 'You have been logged out.');
redirect('login.php');
