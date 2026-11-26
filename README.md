# Zammad integration into Nextcloud

🦜 Interact with your tickets in Nextcloud!

This app provides a dashboard widget to see your main Zammad notifications, a search provider for tickets and notifications for new open tickets.

## 🔧 Configuration

### User settings

The account configuration happens in the "Connected accounts" user settings section. It requires to create a personal access token in your Zammad settings.

A link to the "Connected accounts" user settings section will be displayed in the widget for users who didn't configure a Zammad account.

### Admin settings

There also is a "Connected accounts" **admin** settings section if you want to allow your Nextcloud users to use OAuth to authenticate to a specific Zammad instance.

## 🤖 Using Zammad with the Assistant

With this app and the [Context Agent](https://github.com/nextcloud/context_agent), the Nextcloud Assistant can list Zammad tickets and read a ticket with its whole conversation. It works with each user's own Zammad connection (see "User settings" above): the Assistant never handles a Zammad token, and it only sees what the user can see in Zammad, internal notes included if their Zammad account can see them.

- **Token permissions:** users need a Zammad personal access token with agent access to tickets (`ticket.agent`). Users connected with OAuth have the permissions of their Zammad account.
- **Ticket lists** use Zammad's ticket search with its query syntax (`state.name:new`, `priority.name:"3 high"`, custom fields by their internal name). Zammad needs Elasticsearch for field queries; without it, a query is matched as plain text.
- **One ticket** comes with its articles as plain text, without quoted mail history. Long tickets are cut to what the Context Agent has room for, keeping the newest articles. Attachments are listed, not downloaded.
- **Internal notes** can be added by the Assistant after the user confirms each one. They are plain text, written with the user's token, so they show the user as author. A note is refused if the ticket number does not match the one the Assistant meant.

The same is available to other clients through the OCS API (`/ocs/v2.php/apps/integration_zammad/api/v1/tickets`, `.../ticket?ticket=<ID or link>` and `POST .../ticket/notes`), described in [openapi.json](openapi.json).

## **🛠️ State of maintenance**

While there are many things that could be done to further improve this app, the app is currently maintained with **limited effort**. This means:

- The main functionality works for the majority of the use cases
- We will ensure that the app will continue to work like this for future releases and we will fix bugs that we classify as 'critical'
- We will not invest further development resources ourselves in advancing the app with new features
- We do review and enthusiastically welcome community PR's

We would be more than excited if you would like to collaborate with us. We will merge pull requests for new features and fixes. We also would love to welcome co-maintainers.

If there is a strong business case for any development of this app, we will consider your wishes for our roadmap. Please [contact your account manager](https://nextcloud.com/enterprise/) to talk about the possibilities.
