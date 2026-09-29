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
 * Unit tests for enrol_donation_plugin: instance edition, restore, deletion and enrol page hook.
 *
 * @package    enrol_donation
 * @category   test
 * @copyright  2026 Ai2You
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_donation;

/**
 * Unit tests for enrol_donation_plugin.
 *
 * @coversDefaultClass \enrol_donation_plugin
 */
final class plugin_test extends \advanced_testcase {
    /** @var int|null cached core_payment account id, shared across the valid-data fixture. */
    private $accountid = null;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Returns the enrol_donation test data generator.
     *
     * @return \enrol_donation_generator
     */
    protected function donation_generator(): \enrol_donation_generator {
        return $this->getDataGenerator()->get_plugin_generator('enrol_donation');
    }

    /**
     * Returns the enrol_donation plugin instance under test.
     *
     * @return \enrol_donation_plugin
     */
    protected function plugin(): \enrol_donation_plugin {
        return enrol_get_plugin('donation');
    }

    /**
     * Returns a reusable core_payment account id (paypal gateway), creating it once and caching it.
     *
     * @return int
     */
    protected function payment_account_id(): int {
        if ($this->accountid === null) {
            $account = $this->getDataGenerator()->get_plugin_generator('core_payment')
                ->create_payment_account(['gateways' => 'paypal']);
            $this->accountid = (int) $account->get('id');
        }
        return $this->accountid;
    }

    /**
     * A minimal set of instance-edit-form field values that passes edit_instance_validation()
     * unmodified. Individual tests override the field(s) they want to break.
     *
     * @param \stdClass $instance
     * @return array
     */
    protected function valid_instance_form_data(\stdClass $instance): array {
        return [
            'id' => $instance->id,
            'courseid' => $instance->courseid,
            'name' => 'Donation',
            'status' => ENROL_INSTANCE_ENABLED,
            'cost' => '5.00',
            'customchar1' => '',
            'customchar2' => '500.00',
            'customchar3' => '',
            'customint1' => $this->payment_account_id(),
            'currency' => 'EUR',
            'roleid' => $instance->roleid,
            'enrolperiod' => 0,
            'enrolstartdate' => 0,
            'enrolenddate' => 0,
        ];
    }

    // Tests for edit_instance_validation().

    /**
     * edit_instance_validation() rejects a minimum donation of zero.
     *
     * @covers ::edit_instance_validation
     */
    public function test_edit_instance_validation_rejects_zero_minimum(): void {
        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $data = $this->valid_instance_form_data($instance);
        $data['cost'] = '0';

        $errors = $this->plugin()->edit_instance_validation($data, [], $instance, $context);

        $this->assertArrayHasKey('cost', $errors);
    }

    /**
     * edit_instance_validation() rejects a negative minimum donation.
     *
     * @covers ::edit_instance_validation
     */
    public function test_edit_instance_validation_rejects_negative_minimum(): void {
        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $data = $this->valid_instance_form_data($instance);
        $data['cost'] = '-5';

        $errors = $this->plugin()->edit_instance_validation($data, [], $instance, $context);

        $this->assertArrayHasKey('cost', $errors);
    }

    /**
     * edit_instance_validation() rejects an empty maximum donation, since it is required.
     *
     * @covers ::edit_instance_validation
     */
    public function test_edit_instance_validation_rejects_empty_maximum(): void {
        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $data = $this->valid_instance_form_data($instance);
        $data['customchar2'] = '';

        $errors = $this->plugin()->edit_instance_validation($data, [], $instance, $context);

        $this->assertArrayHasKey('customchar2', $errors);
    }

    /**
     * edit_instance_validation() rejects a maximum donation lower than the minimum.
     *
     * @covers ::edit_instance_validation
     */
    public function test_edit_instance_validation_rejects_maximum_below_minimum(): void {
        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $data = $this->valid_instance_form_data($instance);
        $data['cost'] = '10.00';
        $data['customchar2'] = '5.00';

        $errors = $this->plugin()->edit_instance_validation($data, [], $instance, $context);

        $this->assertArrayHasKey('customchar2', $errors);
    }

    /**
     * edit_instance_validation() rejects a maximum donation above the technical hard ceiling.
     *
     * @covers ::edit_instance_validation
     */
    public function test_edit_instance_validation_rejects_maximum_above_hard_max(): void {
        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $data = $this->valid_instance_form_data($instance);
        $data['customchar2'] = (string) (\enrol_donation\local\intent::HARD_MAX + 1);

        $errors = $this->plugin()->edit_instance_validation($data, [], $instance, $context);

        $this->assertArrayHasKey('customchar2', $errors);
    }

    /**
     * edit_instance_validation() rejects a currency not supported by the payment subsystem.
     *
     * @covers ::edit_instance_validation
     */
    public function test_edit_instance_validation_rejects_unsupported_currency(): void {
        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $data = $this->valid_instance_form_data($instance);
        $data['currency'] = 'XXX';

        $errors = $this->plugin()->edit_instance_validation($data, [], $instance, $context);

        $this->assertArrayHasKey('currency', $errors);
    }

    /**
     * edit_instance_validation() rejects enabling the instance without a payment account.
     *
     * @covers ::edit_instance_validation
     */
    public function test_edit_instance_validation_rejects_missing_payment_account_when_enabled(): void {
        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $data = $this->valid_instance_form_data($instance);
        $data['status'] = ENROL_INSTANCE_ENABLED;
        $data['customint1'] = 0;

        $errors = $this->plugin()->edit_instance_validation($data, [], $instance, $context);

        $this->assertNotEmpty($errors);
    }

    /**
     * edit_instance_validation() rejects a suggested donation outside the minimum/maximum range.
     *
     * @covers ::edit_instance_validation
     */
    public function test_edit_instance_validation_rejects_suggested_outside_range(): void {
        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $data = $this->valid_instance_form_data($instance);
        $data['cost'] = '10.00';
        $data['customchar2'] = '100.00';
        $data['customchar1'] = '150.00';

        $errors = $this->plugin()->edit_instance_validation($data, [], $instance, $context);

        $this->assertArrayHasKey('customchar1', $errors);
    }

    /**
     * edit_instance_validation() accepts an empty suggested donation, since it is optional.
     *
     * @covers ::edit_instance_validation
     */
    public function test_edit_instance_validation_accepts_empty_suggested(): void {
        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $data = $this->valid_instance_form_data($instance);
        $data['customchar1'] = '';

        $errors = $this->plugin()->edit_instance_validation($data, [], $instance, $context);

        $this->assertArrayNotHasKey('customchar1', $errors);
    }

    /**
     * edit_instance_validation() accepts an empty support contact, since it is optional.
     *
     * @covers ::edit_instance_validation
     */
    public function test_edit_instance_validation_accepts_empty_support_contact(): void {
        $instance = $this->donation_generator()->create_instance();
        $context = \context_course::instance($instance->courseid);
        $data = $this->valid_instance_form_data($instance);
        $data['customchar3'] = '';

        $errors = $this->plugin()->edit_instance_validation($data, [], $instance, $context);

        $this->assertArrayNotHasKey('customchar3', $errors);
    }

    /**
     * edit_instance_validation() rejects a role the editing user is not allowed to assign.
     *
     * @covers ::edit_instance_validation
     */
    public function test_edit_instance_validation_rejects_roleid_outside_assignable_intersection(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $course = get_course($instance->courseid);
        $context = \context_course::instance($course->id);
        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $data = $this->valid_instance_form_data($instance);
        $data['roleid'] = $managerrole->id;

        $errors = $this->plugin()->edit_instance_validation($data, [], $instance, $context);

        $this->assertArrayHasKey('roleid', $errors);
    }

    /**
     * update_instance() requires the config capability, even for an editingteacher.
     *
     * @covers ::update_instance
     */
    public function test_update_instance_requires_config_capability_even_for_editingteacher(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance(['cost' => '5.00']);
        $course = get_course($instance->courseid);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $data = (object) $this->valid_instance_form_data($instance);
        $data->cost = '50.00';

        try {
            $this->plugin()->update_instance($instance, $data);
            $this->fail('Expected a required_capability_exception: editingteacher lacks enrol/donation:config.');
        } catch (\required_capability_exception $e) {
            // Expected.
            unset($e);
        }

        $this->assertEquals('5.00', $DB->get_field('enrol', 'cost', ['id' => $instance->id]));
    }

    // Tests for restore_instance(): roleid clamp (red team SEC-3).

    /**
     * restore_instance() clamps a manager roleid to the default when the restoring teacher can't assign it.
     *
     * @covers ::restore_instance
     */
    public function test_restore_instance_clamps_manager_roleid_to_default_for_restoring_teacher(): void {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $this->setAdminUser();

        // The donation enrol plugin ships disabled by default (admin must opt in via Site
        // administration > Plugins > Enrolments); enable it here so
        // backup/moodle2/restore_stepslib.php::process_enrol() does not skip restore_instance()
        // via its enrol_is_enabled() gate.
        set_config('enrol_plugins_enabled', 'manual,donation');

        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);

        $instance = $this->donation_generator()->create_instance([
            'roleid' => $managerrole->id,
            'customint1' => $this->payment_account_id(),
        ]);
        $course1 = get_course($instance->courseid);

        // Back up course1 (admin performs the backup).
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course1->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $bc->destroy();

        $coursecontext = \context_course::instance($course1->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($coursecontext->id, 'backup', 'course', false, 'id ASC');
        $backupfile = reset($files);
        $path = $CFG->tempdir . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . $backupid;
        $fp = get_file_packer('application/vnd.moodle.backup');
        $fp->extract_to_pathname($backupfile, $path);

        // A teacher without manager-assign capability restores it into a fresh course.
        $course2 = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($teacher->id, $course2->id, 'editingteacher');

        // Without moodle/restore:userinfo, backup/util/checks/restore_check.class.php locks the
        // restore's 'users' setting to false, which in turn makes
        // backup/moodle2/restore_course_task.class.php::build() skip adding
        // restore_enrolments_structure_step entirely for TARGET_EXISTING_ADDING ("keep current
        // enrolments unchanged") - restore_instance() would never be called at all. editingteacher
        // does not have this capability by default (lib/db/access.php: only 'manager' does), so
        // grant it here, scoped to course2's context only, matching the pattern used by core's own
        // backup/moodle2/tests/moodle2_test.php::prepare_for_enrolments_test(). This is orthogonal
        // to moodle/role:assign (which the teacher deliberately still lacks for the manager role,
        // per this test's premise).
        $course2context = \context_course::instance($course2->id);
        assign_capability('moodle/restore:userinfo', CAP_ALLOW, $teacherroleid, $course2context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($teacher);

        $rc = new \restore_controller(
            $backupid,
            $course2->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $teacher->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $restored = $DB->get_record('enrol', ['courseid' => $course2->id, 'enrol' => 'donation'], '*', MUST_EXIST);

        $this->assertNotEquals($managerrole->id, $restored->roleid);
    }

    // Tests for delete_instance().

    /**
     * delete_instance() removes the instance's donation intents but keeps its payment records.
     *
     * @covers ::delete_instance
     */
    public function test_delete_instance_removes_intents_but_keeps_payments(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $paidintent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $paymentid = $this->donation_generator()->create_payment_for_intent($paidintent, $user->id);
        $this->donation_generator()->create_intent(['instanceid' => $instance->id]);

        $this->plugin()->delete_instance($instance);

        $this->assertEquals(0, $DB->count_records('enrol_donation_intent', ['instanceid' => $instance->id]));
        $this->assertTrue($DB->record_exists('payments', ['id' => $paymentid]));
        $this->assertFalse($DB->record_exists('enrol', ['id' => $instance->id]));
    }

    // Tests for enrol_page_hook(): self-healing + PRG-less summary.

    /**
     * enrol_page_hook() self-heals a pending payment and hides the donation form once enrolled.
     *
     * @covers ::enrol_page_hook
     */
    public function test_enrol_page_hook_delivers_pending_payment_and_hides_form(): void {
        $instance = $this->donation_generator()->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id);
        $this->setUser($user);

        $html = $this->plugin()->enrol_page_hook($instance);

        $context = \context_course::instance($instance->courseid);
        $this->assertTrue(is_enrolled($context, $user));
        $this->assertStringNotContainsString('name="amount"', $html);
    }

    /**
     * enrol_page_hook() shows the instance's configured support contact when delivery fails.
     *
     * @covers ::enrol_page_hook
     */
    public function test_enrol_page_hook_shows_configured_support_contact_when_delivery_fails(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance(['customchar3' => 'support@ong.example']);
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id);
        // Break delivery: the enrol instance row disappears from under the intent.
        $DB->delete_records('enrol', ['id' => $instance->id]);
        $this->setUser($user);

        $html = $this->plugin()->enrol_page_hook($instance);

        $this->assertStringContainsString('support@ong.example', $html);
        $this->assertStringNotContainsString('name="amount"', $html);
    }

    /**
     * enrol_page_hook() HTML-escapes the support contact when delivery fails.
     *
     * @covers ::enrol_page_hook
     */
    public function test_enrol_page_hook_escapes_support_contact_when_delivery_fails(): void {
        global $DB;

        $instance = $this->donation_generator()->create_instance(['customchar3' => '<script>alert(1)</script>']);
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id);
        $DB->delete_records('enrol', ['id' => $instance->id]);
        $this->setUser($user);

        $html = $this->plugin()->enrol_page_hook($instance);

        $this->assertStringNotContainsString('<script>', $html);
    }

    /**
     * enrol_page_hook() falls back to the site's supportemail when no instance contact is configured.
     *
     * @covers ::enrol_page_hook
     */
    public function test_enrol_page_hook_falls_back_to_site_supportemail_when_no_contact_configured(): void {
        global $CFG, $DB;

        $CFG->supportemail = 'siteops@example.com';
        $instance = $this->donation_generator()->create_instance(['customchar3' => null]);
        $user = $this->getDataGenerator()->create_user();
        $intent = $this->donation_generator()->create_intent(['instanceid' => $instance->id]);
        $this->donation_generator()->create_payment_for_intent($intent, $user->id);
        $DB->delete_records('enrol', ['id' => $instance->id]);
        $this->setUser($user);

        $html = $this->plugin()->enrol_page_hook($instance);

        $this->assertStringContainsString('siteops@example.com', $html);
    }

    /**
     * enrol_page_hook() renders the payment summary with a data-itemid attribute and no donationintent param.
     *
     * @covers ::enrol_page_hook
     */
    public function test_enrol_page_hook_post_renders_summary_with_itemid_and_no_donationintent_param(): void {
        $instance = $this->donation_generator()->create_instance([
            'customint1' => $this->payment_account_id(),
            'cost' => '5.00',
            'customchar2' => '500.00',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $formclass = '\\enrol_donation\\form\\donation_amount_form';
        $identifier = $instance->id . '_' . str_replace('\\', '_', ltrim($formclass, '\\'));
        $formclass::mock_submit([
            'amount' => '10.00',
            'instance' => $instance->id,
            'id' => $instance->id,
        ], [], 'post', $identifier);

        $html = $this->plugin()->enrol_page_hook($instance);

        $this->assertStringContainsString('data-itemid', $html);
        $this->assertStringNotContainsString('donationintent', $html);
    }

    /**
     * The donation amount form pre-fills the field with the instance's suggested amount.
     *
     * @covers \enrol_donation\form\donation_amount_form
     */
    public function test_donation_amount_form_prefills_suggested_amount(): void {
        $instance = $this->donation_generator()->create_instance([
            'cost' => '5.00',
            'customchar1' => '20.00',
            'customchar2' => '500.00',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $form = new \enrol_donation\form\donation_amount_form(null, ['instance' => $instance]);

        $this->assertStringContainsString('20.00', $form->render());
    }
}
