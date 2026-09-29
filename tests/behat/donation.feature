@enrol @enrol_donation
Feature: Donating to access a course via enrol_donation

  # Field labels below (Minimum donation, Suggested donation, Maximum donation, Support
  # contact, Donation amount) are inferred from the plan (phase-02/phase-03) ahead of the
  # actual lang/en/enrol_donation.php strings, which fase 3 of /cocinar still has to write.
  # Align these labels with the real strings once they exist.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | teacher1 | Teacher   | 1        | teacher1@example.com  |
      | student1 | Student   | 1        | student1@example.com  |
      | manager1 | Manager   | 1        | manager1@example.com  |
    And the following "courses" exist:
      | fullname | shortname | format | summary |
      | Course 1 | C1        | topics |         |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | manager1 | C1     | manager        |
    And the following "core_payment > payment accounts" exist:
      | name     | gateways |
      | Account1 | paypal   |
    And I log in as "admin"
    And I navigate to "Plugins > Enrolments > Manage enrol plugins" in site administration
    And I click on "Enable" "link" in the "Donation" "table_row"
    And I log out
    And I log in as "manager1"
    And I am on the "Course 1" "enrolment methods" page
    And I select "Donation" from the "Add method" singleselect
    And I set the following fields to these values:
      | Payment account    | Account1 |
      | Minimum donation    | 10       |
      | Suggested donation  | 20       |
      | Maximum donation    | 500      |
      | Currency            | Euro     |
    And I press "Add method"
    And I log out

  Scenario: Manager cannot add a donation method with a zero minimum and an empty maximum
    Given I log in as "manager1"
    And I am on the "Course 1" "enrolment methods" page
    And I select "Donation" from the "Add method" singleselect
    And I set the following fields to these values:
      | Payment account    | Account1 |
      | Minimum donation    | 0        |
      | Maximum donation    |          |
      | Currency            | Euro     |
    And I press "Add method"
    Then I should see "greater than 0"

  Scenario: Manager cannot add a donation method with a suggested amount outside the min/max range
    Given I log in as "manager1"
    And I am on the "Course 1" "enrolment methods" page
    And I select "Donation" from the "Add method" singleselect
    And I set the following fields to these values:
      | Payment account    | Account1 |
      | Minimum donation    | 10       |
      | Maximum donation    | 50       |
      | Suggested donation  | 100      |
      | Currency            | Euro     |
    And I press "Add method"
    Then I should see "between the minimum and the maximum"

  @javascript
  Scenario: Student sees the minimum and maximum, with the amount field prefilled with the suggested donation
    When I log in as "student1"
    And I am on course index
    And I follow "Course 1"
    Then I should see "10"
    And I should see "500"
    And the field "Donation amount" matches value "20"

  @javascript
  Scenario: Student sees an error when the donation is below the configured minimum
    When I log in as "student1"
    And I am on course index
    And I follow "Course 1"
    And I set the field "Donation amount" to "5"
    And I press "Continue"
    Then I should see "at least"

  @javascript
  Scenario: A valid donation renders the summary in the same response and offers to pay
    When I log in as "student1"
    And I am on course index
    And I follow "Course 1"
    And I set the field "Donation amount" to "25"
    And I press "Continue"
    Then I should see "25"
    And I press "Select payment type"
    And I should see "PayPal" in the "Select payment type" "dialogue"
    And I click on "Cancel" "button" in the "Select payment type" "dialogue"

  @javascript
  Scenario: Change amount returns the student to the donation form
    When I log in as "student1"
    And I am on course index
    And I follow "Course 1"
    And I set the field "Donation amount" to "25"
    And I press "Continue"
    And I click on "Change amount" "link"
    Then I should see "Donation amount"

  Scenario: Guest is prompted to log in instead of seeing the donation form
    When I log in as "guest"
    And I am on course index
    And I follow "Course 1"
    Then I should see "Log in to the site"

  @javascript
  Scenario: Two donation methods in the same course keep their own minimum and maximum
    Given I log in as "manager1"
    And I am on the "Course 1" "enrolment methods" page
    And I select "Donation" from the "Add method" singleselect
    And I set the following fields to these values:
      | Payment account    | Account1 |
      | Minimum donation    | 50       |
      | Maximum donation    | 1000     |
      | Currency            | Euro     |
    And I press "Add method"
    And I log out
    When I log in as "student1"
    And I am on course index
    And I follow "Course 1"
    Then I should see "10"
    And I should see "500"
    And I should see "50"
    And I should see "1,000"
