Feature: federation/attachments
  Background:
    Given using server "REMOTE"
    And user "participant2" exists
    And the following "spreed" app config is set
      | federation_enabled | yes |
    And invoking occ with "config:system:set sharing.federation.allowHttpFallback --value=true --type=boolean"
    And the command was successful
    And using server "LOCAL"
    And user "participant1" exists
    And the following "spreed" app config is set
      | federation_enabled | yes |
    And invoking occ with "config:system:set sharing.federation.allowHttpFallback --value=true --type=boolean"
    And the command was successful
    And user "participant1" creates room "room" (v4)
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
    And using server "LOCAL"

  Scenario: File from the conversation folder is shown from the remote participant's own server
    When user "participant1" uploads file "photo.txt" with content "hello" to conversation folder for room "room" with name "room"
    And user "participant1" posts file "photo.txt" from conversation folder of room "room" with name "room" with 200 (v1)
    And using server "REMOTE"
    # The test servers don't trust each other, so the federated share waits for acceptance
    Then user "participant2" sees the last file message in room "LOCAL::room" as not available
    # The conversation list comes from the per-room cache, which only keeps the file name
    And user "participant2" is participant of the following rooms (v4)
      | id          | type | lastMessage |
      | LOCAL::room | 2    | photo.txt   |
    When user "participant2" accepts all pending federated shares
    Then user "participant2" sees the last file message in room "LOCAL::room" as local file "photo.txt"

  Scenario: Files posted before the participant joined are shared by the background job
    Given user "participant1" creates room "early" (v4)
      | roomType | 2 |
      | roomName | early |
    And user "participant1" uploads file "early.txt" with content "old" to conversation folder for room "early" with name "early"
    And user "participant1" posts file "early.txt" from conversation folder of room "early" with name "early" with 200 (v1)
    And user "participant1" adds federated_user "participant2" to room "early" with 200 (v4)
    And using server "REMOTE"
    And user "participant2" has the following invitations (v1)
      | remoteServerUrl | remoteToken | state | inviterCloudId     | inviterDisplayName       |
      | LOCAL           | early       | 0     | participant1@LOCAL | participant1-displayname |
      | LOCAL           | room        | 1     | participant1@LOCAL | participant1-displayname |
    And user "participant2" accepts invite to room "early" of server "LOCAL" with 200 (v1)
      | id           | name  | type | remoteServer | remoteToken |
      | LOCAL::early | early | 2    | LOCAL        | early       |
    And using server "LOCAL"
    When run "OCA\Talk\BackgroundJob\EnsureAttachmentShares" background jobs
    And using server "REMOTE"
    And user "participant2" accepts all pending federated shares
    Then user "participant2" sees the last file message in room "LOCAL::early" as local file "early.txt"

  Scenario: Removing the participant removes their shares
    When user "participant1" uploads file "photo.txt" with content "hello" to conversation folder for room "room" with name "room"
    And user "participant1" posts file "photo.txt" from conversation folder of room "room" with name "room" with 200 (v1)
    And using server "REMOTE"
    And user "participant2" accepts all pending federated shares
    And user "participant2" has 1 accepted federated shares
    And using server "LOCAL"
    When user "participant1" removes remote "participant2" from room "room" with 200 (v4)
    And using server "REMOTE"
    Then user "participant2" has 0 accepted federated shares

  Scenario: File from the remote participant is shown from each viewer's own server
    Given using server "REMOTE"
    When user "participant2" uploads file "bill.txt" with content "from bill" to conversation folder for room "LOCAL::room" with name "room"
    And user "participant2" posts file "bill.txt" from conversation folder of room "LOCAL::room" with name "room" with 200 (v1)
    # The sender's server finds the file in the sender's own storage
    Then user "participant2" sees the last file message in room "LOCAL::room" as local file "bill.txt"
    And using server "LOCAL"
    # The test servers don't trust each other, so the federated share waits for acceptance
    And user "participant1" sees the last file message in room "room" as not available
    When user "participant1" accepts all pending federated shares
    Then user "participant1" sees the last file message in room "room" as local file "bill.txt"

  Scenario: Removing a user of the host removes their copy of the remote participant's files
    Given user "participant3" exists
    And user "participant1" adds user "participant3" to room "room" with 200 (v4)
    And using server "REMOTE"
    And user "participant2" uploads file "bill.txt" with content "from bill" to conversation folder for room "LOCAL::room" with name "room"
    And user "participant2" posts file "bill.txt" from conversation folder of room "LOCAL::room" with name "room" with 200 (v1)
    And using server "LOCAL"
    And user "participant3" accepts all pending federated shares
    And user "participant3" has 1 accepted federated shares
    When user "participant1" removes user "participant3" from room "room" with 200 (v4)
    Then user "participant3" has 0 accepted federated shares
