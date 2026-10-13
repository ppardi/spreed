Feature: federation/user-statuses

  Background:
    Given using server "REMOTE"
    And user "participant2" exists
    And user "participant3" exists
    And the following "spreed" app config is set
      | federation_enabled | yes |
    And using server "LOCAL"
    And user "participant1" exists
    And the following "spreed" app config is set
      | federation_enabled | yes |

  Scenario: User statuses are added to the participant request in federated conversations
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds federated_user "participant2" to room "room" with 200 (v4)
    And user "participant1" adds federated_user "participant3" to room "room" with 200 (v4)
    And using server "REMOTE"
    And user "participant2" has the following invitations (v1)
      | remoteServerUrl | remoteToken | state | inviterCloudId     | inviterDisplayName       |
      | LOCAL           | room        | 0     | participant1@LOCAL | participant1-displayname |
    And user "participant2" accepts invite to room "room" of server "LOCAL" with 200 (v1)
      | id          | name | type | remoteServer | remoteToken |
      | LOCAL::room | room | 2    | LOCAL        | room        |
    Then user "participant2" set status to "away" with 200 (v1)
    And user "participant3" has the following invitations (v1)
      | remoteServerUrl | remoteToken | state | inviterCloudId     | inviterDisplayName       |
      | LOCAL           | room        | 0     | participant1@LOCAL | participant1-displayname |
    And user "participant3" accepts invite to room "room" of server "LOCAL" with 200 (v1)
      | id          | name | type | remoteServer | remoteToken |
      | LOCAL::room | room | 2    | LOCAL        | room        |
    Then user "participant3" set status to "dnd" with 200 (v1)
    And user "participant2" is participant of room "LOCAL::room" (v4)
    And user "participant2" sees the following attendees with status in room "LOCAL::room" with 200 (v4)
      | actorType       | actorId                   | participantType | status  |
      | federated_users | participant1@{$LOCAL_URL} | 1               | offline |
      | users           | participant2              | 3               | away    |
      | users           | participant3              | 3               | dnd     |

  Scenario: A federated participant's status is visible to the host
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds federated_user "participant2" to room "room" with 200 (v4)
    And using server "REMOTE"
    And user "participant2" has the following invitations (v1)
      | remoteServerUrl | remoteToken | state | inviterCloudId     | inviterDisplayName       |
      | LOCAL           | room        | 0     | participant1@LOCAL | participant1-displayname |
    And user "participant2" accepts invite to room "room" of server "LOCAL" with 200 (v1)
      | id          | name | type | remoteServer | remoteToken |
      | LOCAL::room | room | 2    | LOCAL        | room        |
    And user "participant2" set status to "dnd" with 200 (v1)
    Given using server "LOCAL"
    Then user "participant1" sees the following attendees with status in room "room" with 200 (v4)
      | actorType       | actorId                    | participantType | status  |
      | users           | participant1               | 1               | offline |
      | federated_users | participant2@{$REMOTE_URL} | 3               | dnd     |

  Scenario: A host user's status is visible to a federated participant
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds federated_user "participant2" to room "room" with 200 (v4)
    And user "participant1" set status to "away" with 200 (v1)
    And using server "REMOTE"
    And user "participant2" has the following invitations (v1)
      | remoteServerUrl | remoteToken | state | inviterCloudId     | inviterDisplayName       |
      | LOCAL           | room        | 0     | participant1@LOCAL | participant1-displayname |
    And user "participant2" accepts invite to room "room" of server "LOCAL" with 200 (v1)
      | id          | name | type | remoteServer | remoteToken |
      | LOCAL::room | room | 2    | LOCAL        | room        |
    And user "participant2" is participant of room "LOCAL::room" (v4)
    Then user "participant2" sees the following attendees with status in room "LOCAL::room" with 200 (v4)
      | actorType       | actorId                   | participantType | status |
      | federated_users | participant1@{$LOCAL_URL} | 1               | away   |
      | users           | participant2              | 3               | ABSENT |
