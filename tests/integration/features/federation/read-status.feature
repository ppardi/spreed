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

  Scenario: A federated participant that shares its read status moves the host's common read marker
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds federated_user "participant2" to room "room" with 200 (v4)
    Given using server "REMOTE"
    And user "participant2" has the following invitations (v1)
      | remoteServerUrl | remoteToken | state | inviterCloudId     | inviterDisplayName       |
      | LOCAL           | room        | 0     | participant1@LOCAL | participant1-displayname |
    And user "participant2" accepts invite to room "room" of server "LOCAL" with 200 (v1)
      | id          | name | type | remoteServer | remoteToken |
      | LOCAL::room | room | 2    | LOCAL        | room        |
    And user "participant2" sets setting "read_status_privacy" to 0 with 200 (v1)
    Given using server "LOCAL"
    And user "participant1" sends message "Message 1" to room "room" with 201
    Given using server "REMOTE"
    # participant2's own attendee row on the host starts PRIVACY_PRIVATE (ParticipantService::
    # addUsers()) and is excluded from the minimum until a proxied request of theirs carries the
    # public read-privacy header (ReadPrivacySync::applyFromRequest); this first read is what
    # flips it, and it also leaves participant2's own marker sitting at Message 1.
    And user "participant2" reads message "Message 1" in room "LOCAL::room" with 200
    Given using server "LOCAL"
    And user "participant1" sends message "Message 2" to room "room" with 201
    And user "participant1" reads message "Message 2" in room "room" with 200

    # participant2 now counts but is still sitting at Message 1, so the minimum is held there.
    # "less than" rather than an exact id because that id is not knowable from the feature file —
    # the same reason upstream provides this variant (FeatureContext:3771).
    # NOT "has no last common read message header": the header is emitted whenever the *requesting*
    # participant's read privacy is public, whatever its value (ChatController.php:1891).
    Then last response has last common read message header less than "Message 2"

    Given using server "REMOTE"
    When user "participant2" reads message "Message 2" in room "LOCAL::room" with 200
    Given using server "LOCAL"
    And user "participant1" reads message "Message 2" in room "room" with 200
    Then last response has last common read message header set to "Message 2"

  Scenario: A federated participant that hides its read status is ignored by the host
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds federated_user "participant2" to room "room" with 200 (v4)
    Given using server "REMOTE"
    And user "participant2" has the following invitations (v1)
      | remoteServerUrl | remoteToken | state | inviterCloudId     | inviterDisplayName       |
      | LOCAL           | room        | 0     | participant1@LOCAL | participant1-displayname |
    And user "participant2" accepts invite to room "room" of server "LOCAL" with 200 (v1)
      | id          | name | type | remoteServer | remoteToken |
      | LOCAL::room | room | 2    | LOCAL        | room        |
    And user "participant2" sets setting "read_status_privacy" to 1 with 200 (v1)
    Given using server "LOCAL"
    And user "participant1" sends message "Message 1" to room "room" with 201
    Given using server "REMOTE"
    # Read it only after it exists, and while private: this must leave the host's marker alone
    And user "participant2" reads message "Message 1" in room "LOCAL::room" with 200
    Given using server "LOCAL"
    And user "participant1" reads message "Message 1" in room "room" with 200

    # participant2 stays private on the host, so it neither holds the marker back nor is disclosed
    Then last response has last common read message header set to "Message 1"

  Scenario: Marking the conversation unread on the remote side lowers the host's marker again
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds federated_user "participant2" to room "room" with 200 (v4)
    Given using server "REMOTE"
    And user "participant2" has the following invitations (v1)
      | remoteServerUrl | remoteToken | state | inviterCloudId     | inviterDisplayName       |
      | LOCAL           | room        | 0     | participant1@LOCAL | participant1-displayname |
    And user "participant2" accepts invite to room "room" of server "LOCAL" with 200 (v1)
      | id          | name | type | remoteServer | remoteToken |
      | LOCAL::room | room | 2    | LOCAL        | room        |
    And user "participant2" sets setting "read_status_privacy" to 0 with 200 (v1)
    Given using server "LOCAL"
    And user "participant1" sends message "Message 1" to room "room" with 201
    And user "participant1" sends message "Message 2" to room "room" with 201
    Given using server "REMOTE"
    And user "participant2" reads message "Message 2" in room "LOCAL::room" with 200
    Given using server "LOCAL"
    And user "participant1" reads message "Message 2" in room "room" with 200
    Then last response has last common read message header set to "Message 2"

    # The marker must be able to fall again, or the "read by everyone" tick would stay on a
    # message nobody has read any more (CommonReadStore is last-write-wins for this reason)
    Given using server "REMOTE"
    When user "participant2" marks room "LOCAL::room" as unread with 200
    Given using server "LOCAL"
    And user "participant1" reads message "Message 2" in room "room" with 200
    Then last response has last common read message header less than "Message 2"
