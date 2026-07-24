<?php

namespace FitnessClub\Http\Controllers\Dashboard;

use FitnessClub\Http\Controllers\Controller;

if (!defined('ABSPATH')) {
  exit();
}

class DashboardController extends Controller
{
  public function index()
  {
    return FitnessClub()
      ->view('dashboard.index')
      ->withAdminStyle('prism')
      ->withAdminScript('prism')
      ->withAdminStyle('fitnessclub-common')
      ->withAdminAppsScript('app');
  }
}
