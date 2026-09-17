Feature: federation/search
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

  Scenario: Remote participant searches a conversation hosted on another server
    Given user "participant1" sends message "Message 1" to room "room" with 201
    And user "participant1" sends message "Something else" to room "room" with 201
    And using server "REMOTE"
    And user "participant2" sends message "Message 2" to room "LOCAL::room" with 201
    # The remote server forwards the search to the host; results link to the remote server's copy of the conversation
    When user "participant2" searches for messages with "essa" in room "LOCAL::room" with 200
      | title                    | subline   | attributes.conversation | attributes.messageId |
      | participant2-displayname | Message 2 | LOCAL::room             | Message 2            |
      | participant1-displayname | Message 1 | LOCAL::room             | Message 1            |
    When user "participant2" searches for messages with "else" in room "LOCAL::room" with 200
      | title                    | subline        | attributes.conversation | attributes.messageId |
      | participant1-displayname | Something else | LOCAL::room             | Something else       |
    # "From" filter: the remote server sends participant2's cloud id, the host filters on the actor it stores for them
    When user "participant2" searches for messages with "person:USER(participant2) essa" in room "LOCAL::room" with 200
      | title                    | subline   | attributes.conversation | attributes.messageId |
      | participant2-displayname | Message 2 | LOCAL::room             | Message 2            |
    When user "participant2" searches for messages with "zzzz" in room "LOCAL::room" with 200
