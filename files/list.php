<?php
/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
OC_Util::checkLoggedIn();

$tmpl = new OCP\Template('circles', 'files/list', '');
$tmpl->printPage();
