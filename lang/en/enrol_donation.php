<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for component 'enrol_donation', language 'en'.
 *
 * @package    enrol_donation
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['alertoverdue'] = '{$a} donation(s) were paid more than 24 hours ago but could not be delivered automatically.';
$string['alertsubject'] = 'Donation enrolments need attention';
$string['alertunidentified'] = '{$a} donation(s) were paid without an identifiable payer and require manual review.';
$string['alreadyenrolled'] = 'Your payment was received and you are now enrolled in this course.';
$string['amountrange'] = 'Minimum {$a->min} — Maximum {$a->max}';
$string['amounttoolarge'] = 'The donation cannot be more than the maximum shown.';
$string['amounttoosmall'] = 'The donation must be at least the minimum shown.';
$string['assignrole'] = 'Assign role';
$string['changeamount'] = 'Change amount';
$string['costerror'] = 'The minimum donation must be a number greater than 0.';
$string['currency'] = 'Currency';
$string['deliverypending'] = 'Your payment was received but your enrolment could not be completed automatically. Please contact {$a} for assistance.';
$string['donation:config'] = 'Configure donation enrol instances';
$string['donation:manage'] = 'Manage enrolled users';
$string['donation:unenrol'] = 'Unenrol users from the course';
$string['donation:unenrolself'] = 'Unenrol self from the course';
$string['donationamount'] = 'Donation amount';
$string['donationamount_help'] = 'Enter how much you would like to donate to enrol in this course. The amount must be within the minimum and maximum shown.';
$string['enrolenddate'] = 'End date';
$string['enrolenddate_help'] = 'If enabled, users can be enrolled until this date only.';
$string['enrolenddaterror'] = 'The enrolment end date cannot be earlier than the start date.';
$string['enrolperiod'] = 'Enrolment duration';
$string['enrolperiod_help'] = 'Length of time that the enrolment is valid, starting with the moment the user donates. If disabled, the enrolment duration will be unlimited.';
$string['enrolstartdate'] = 'Start date';
$string['enrolstartdate_help'] = 'If enabled, users can only be enrolled from this date onwards.';
$string['gotocourse'] = 'Go to the course';
$string['intentalreadypaid'] = 'This donation has already been paid.';
$string['intentretention'] = 'Intent retention (days)';
$string['intentretention_desc'] = 'How long to keep an unpaid donation intent before it is automatically deleted. The site can never set this below 30 days, regardless of this setting.';
$string['invalidamount'] = 'Enter a valid amount, using digits and at most one decimal separator.';
$string['maximumbelowminimum'] = 'The maximum donation cannot be lower than the minimum donation.';
$string['maximumdonation'] = 'Maximum donation';
$string['maximumdonation_help'] = 'The largest amount a student may donate in a single payment. Required: it must be at least the minimum donation (and at least the suggested donation, if set).';
$string['maximumerror'] = 'The maximum donation must be a valid number.';
$string['maximumrequired'] = 'The maximum donation is required.';
$string['maximumtoohigh'] = 'The maximum donation is too high.';
$string['messageprovider:deliveryalert'] = 'Donation delivery alerts';
$string['minimumdonation'] = 'Minimum donation';
$string['minimumdonation_help'] = 'The smallest amount a student may donate to enrol. Must be greater than 0: to offer a free option alongside donations, add a second, free enrolment method (for example "Self enrolment") to the same course.';
$string['mustlogin'] = 'You need to log in before you can donate to enrol in this course.';
$string['nopaymentaccount'] = 'Donation enrolments cannot be enabled without specifying a payment account.';
$string['paymentaccount'] = 'Payment account';
$string['paymentaccount_help'] = 'The payment account that receives donations made through this enrolment method.';
$string['pluginname'] = 'Donation';
$string['pluginname_desc'] = 'The donation enrolment plugin allows a student to enrol in a course by paying a donation of any amount they choose, subject to a per-course minimum and maximum, through Moodle\'s payment subsystem.';
$string['privacy:metadata'] = 'The donation enrolment plugin does not store personal data of its own. Payment records (who paid, how much and when) are stored by the core payment subsystem, not by this plugin.';
$string['purchasedescription'] = 'Donation to enrol in {$a}';
$string['selectpaymenttype'] = 'Select payment type';
$string['status'] = 'Allow donation enrolments';
$string['suggesteddonation'] = 'Suggested donation';
$string['suggesteddonation_help'] = 'An optional amount pre-filled in the donation form to guide students. Leave empty to not suggest an amount. If set, it must be between the minimum and the maximum.';
$string['suggestedoutsiderange'] = 'The suggested donation must be between the minimum and the maximum.';
$string['supportcontact'] = 'Support contact';
$string['supportcontact_help'] = 'Email address, URL or phone number shown to a student if their payment was received but enrolment could not be completed automatically. If left empty, the site\'s default support contact is used.';
$string['taskcleanup'] = 'Clean up stale donation intents';
$string['taskredeliver'] = 'Redeliver paid donations';
$string['toomanydonationattempts'] = 'Too many donation attempts in this session. Please try again later.';
