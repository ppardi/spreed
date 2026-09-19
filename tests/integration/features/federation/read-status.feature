Feature: federation/read-status
  Background:
    Given using server "REMOTE"
    And user "participant2" exists
    And the following "spreed" app config is set
      | federation_enabled | yes |
    And using server "LOCAL"
    Given user "participant1" exists
    Given user "participant3" exists
    And the following "spreed" app config is set
      | federation_enabled | yes |

  Scenario: A federated participant sees the read marker of the host's own user
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds federated_user "participant2" to room "room" with 200 (v4)
    And user "participant1" adds user "participant3" to room "room" with 200 (v4)
    Given using server "REMOTE"
    And user "participant2" has the following invitations (v1)
      | remoteServerUrl | remoteToken | state | inviterCloudId     | inviterDisplayName       |
      | LOCAL           | room        | 0     | participant1@LOCAL | participant1-displayname |
    And user "participant2" accepts invite to room "room" of server "LOCAL" with 200 (v1)
      | id          | name | type | remoteServer | remoteToken |
      | LOCAL::room | room | 2    | LOCAL        | room        |
    Given using server "LOCAL"
    And user "participant1" sends message "Message 1" to room "room" with 201
    And user "participant1" sends message "Message 2" to room "room" with 201
    # participant3 stays behind at Message 1, holding the host's minimum below
    # participant2's own marker so the two values differ
    And user "participant3" reads message "Message 1" in room "room" with 200
    Given using server "REMOTE"
    # participant2's own marker advances to Message 2, so a formatter that fell back to
    # the locally computed value would report Message 2 and the assertion below would fail
    And user "participant2" reads message "Message 2" in room "LOCAL::room" with 200
    Then user "participant2" is participant of the following rooms (v4)
      | id          | type | lastCommonReadMessage |
      | LOCAL::room | 2    | Message 1             |
