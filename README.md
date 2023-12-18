# Roadblocks Silverstripe module

This module creates a database log of sessions, requests and login attempts. Where possible these are attached to an authenticated user.
The gateway then uses a rules based infrustructure to identify requests that should  be flagged, and when sufficent, block the request.
There are two new modeladmin tabs, Sessions and Roadblocks.
These are reports for both of these, and a queued job to remove stale requests logs.

## Sessions

A session is set for all requests now. To avoid this being reset on login the $session_regenerate_id is set to false in the config yml.

## Logs

Logging is done for the session, request and login attempts.
Each request logs the ip address, url, useragent, request type (configurable in ModelAdmin), Verb (GET, POST etc).

## excluded urls

The config/urlignore.yml file contains a list of preg_match values to be excluded from reuest logs. This reduces noise from _resouce requests, and frees up the /dev/build and error pages from being blocked.

## Gateway

The module works by inserting a new gateway after the member authentication has finished.
- Step 1 is to log the request and session data.
- Step 2 is to parse this against any established rules. If there is a rule violation a new roadblock record is created, or an existing one is added to. The roadblock record records the score, a list of rule violations as 'Roadblock Exceptions', and a list of the rules that are in effect against this record.
- Step 3 (optional and configurable) is to notify by email if a new roadblock record is created, or a roadblock comes into effect (Score is greater than or equal to 100.00).
- Step 4 is to check if there is a current roadblock in effect for the request.

## Overriding the Roadblock

If upon examination a roadblock should be removed there is an Admin Override checkbox on the roadblock modelAdmin. This will permit the member, or session to ignore all rules. Only override if you have high trust that the member or session is legitimate.

## Expiring the Roadblock

Setting an expiry value in the config yaml will set an expiry date whenever a score exceeds 100.00. When the expiry is over, on the next request by the member / session that is blocked, 100.00 will be subtracted from the score.
If the score is still over 100 a new expiry date will be set.
To illistrate, suppose I have a rule that is set to 50.00 (cumulative) at the member level, and an expiry is set to 600 seconds.
- The member triggers the rule the first time, a notification is sent to the admin and a roadblock record is created which holds the exception, the rule violated etc, but the user proceeds as normal.
- The rule is triggered a second time, the request is blocked, a new notification is sent to the admin, and the roadbock record is updated. All further requests will be blocked for the member for 10 minutes (600 seconds).
- The user tries a couple more times, building the score up to 200.00 and adding more exceptions to the roadblock record.
- after 10 minutes the user tries again with a request that will not be flagged, this time the expiry is past, and 100.00 is subtracted from the score. The score is still 100.00 so a new expiry for a further 10 minutes is added. The request is blocked.
- after another 10 minutes the user tries again with the first request that will be flagged. This time the score is increased to 150.00 due to the triggered rule, but reduced to 50.00 due to the expiry being past. The request is not blocked and the member can proceed.
This value can be overriden when a new rule is added to the roadblock with an expiry override. The overrides are as follows:
- -1 do not expire the roadblock ever
- 0 use the application default
- greater than 0 the time in seconds to use
The roadblock will record the most stringent violation (ie highest value or -1)
This can also be manually changed in the roadblock itself.

## Rules
Rules are created on a "If false then" basis, this allows for early exit of ligitimate traffic.
When the score exceeds 100.00 a 'Roadblock' will be inforced returning 404 error page or an httprequest exception (configurable).

- Level:- Global will replace Session with IPAddress. Member level will loop through all sessions attached to the member, as well as loging attempts.
- LoginAttemptsStatus:- Consider just Failed, Success, or any login attempt status.
- LoginAttemptsNumber:- How many login attempts to allow before flagging.
- LoginAttemptsStartOffest:- The time period in seconds to look back over the login attempts.
- Count:- The number of requests to a request type, which is a group of url rules eg. /^admin\//
- StartOffset:- The time period in seconds to look back over.
- Verb The request verb for the rule.
    - Any
    - POST
    - GET
    - DELETE
    - PUT
    - CONNECT
    - OPTIONS
    - TRACE
    - PATCH
    - HEAD
- IPAddress:-
  - If 'Allowed' only allow IP Addresses attached to the 'Type' to be accepted.
  - If 'Allowed for group' only allow IP Addresses attached to the 'Type' to be accepted and must be in the specified group.
  - If 'Allowed for permission' only allow IP Addresses attached to the 'Type' to be accepted and have the correct permission.
  - If 'Denied' only flag these IP Addresses allowing all others.
- Group:- A member in this group will cause the rule to trigger.
- ExcludeGroup:- Reverses the above logic, if in the group they will not trigger the rule and further evaluation stops.
- Permission:- Similar to Group above but for the member's permission.
- ExcludePermission:- Similar to ExcludeGroup above but for the member's permission.
- Score:- how much score to attribute to this rule. Scores will bubble up to the overall total for the current user session.
 - A score of 100.00 will automatically block the request.
 - A score of 0 will create a roadblock record, and notification if set, but not add to the overall score. The current request will be blocked.
 - A negative score can be set and will reduce the score, potentially unblocking the session / member / ip.
- ExpiryOverride:- override the default expiry interval with this. -1 don't expire, or positive for time in seconds.
- Cumulative:- If set to 'Yes' the score will keep accumulating each time the rule is violated. Otherwise it is only captured once.
- Status:- Is the rule in effect?
- Notify individually subject & Notify member content
  - if set this will send a separate email notification to the member if known with attached content on each violation of this rule.

## Model Admin

The roadblock model admin allows for administration for every level required, and export / import to aid in setting up rules.
The basic hierarchy for the rules is:
- RoadblockRule
  - RoadblockRequestTypes
    - RoadblockURLRules
    - RoadblockIPRules
  - Roadblocks
  - RoadblockExceptions
  - RoadblockRuleInspectors

## Test 'inspectors'

The roadblock rule inspectors model admin tab allows the creation of test outcomes to validate that the rule is working as intended. The 'Result' will debug all the stages of running the rule against the parameters set up in the inspector. If you are happy the debugging info is correct, copy this into the 'Expected result' and the test will pass.

## Notifications

In addition to the individual notifications, there are configurable flags to send an email notification to the admin and or member's email.
There is also a config value to set how often an email should be sent for a roadblock.
- Notify on first creation of a roadblock (partial) triggered when the request gains a score but is not yet at the threshold.
- Notify info only is created when the score of a rule is set to zero.
- Notify on blocked will send a notification when the roadblock crosses the threshold.
- Notify latest will notify any ongoing activity while a roadblock is in effect.
The email templates are all in the EmailService class so extending becomes much easier.

## Customisation

The rules can be extended to include new variables.
There following extended methods are available in addition to the standard ones:
- updateCaptureRequestData, populate the request log with custom fields / data
- updateCaptureSessionData, populate the session log with custom fields / data
- updateEvaluateRoadblockData, populate the roadblock record with custom fields / data
- updateEvaluateRoadblockExceptionData, populate the roadblock exceptions with custom fields / data
- updateEvaluateMember, Run your own custom rules at the member level
- updateEvaluateSession, run your own custom rules at the session level
- updateExportFields, add or update the csv exports
- updateSetRequestLogData, used to extend the test suite
- updateSetSetLoginAttemptData, used to extend the test suite
- updateSetCurrentTest, used to extend the test suite
- updateInfoNotification, updateMemberInfoNotification, updatePartialNotification, updateMemberPartialNotification, updateBlockedNotification, updateMemberBlockedNotification, updateLatestNotification, updateMemberLatestNotification, updateIndividualNotification
  - These allow additional content in the various notification emails

## trim old request logs

The 'TruncateRequestLogJob' will remove old requests from the request log. It takes two parameters, test and repeat. If test is set the job's message tab will show what data would have been removed. If the repeat parameter is present it will schedule another job to run.
The length of time to keep records and how often to run the job are in the yml config settings:
- keep_log_period
- keep_log_repeat_interval

## License

See [License](LICENSE.md)

This module template defaults to using the "BSD-3-Clause" license. The BSD-3 license is one of the most
permissive open-source license and is used by most Silverstripe CMS module.

## Installation

```sh
composer require aSmithSummer/roadblock
```

## Example configuration

As we are setting a new session for un-authenticated members, to prevent new sessions being created when they log in you should set login_recording to true. This is not fool proof but a big improvement.

in your app's base _config add the following:

```yaml
Silverstripe\Security\Security:
  login_recording: true;
```

You can override the default config in the roadblock module in the usual way. the default config is:
```yaml
---
Name: roadblock_member
---
SilverStripe\Security\Member:
    session_regenerate_id: false
---
Name: roadblock
---
aSmithSummer\Roadblock\Gateways\SessionLogMiddleware:
  show_error_on_blocked: true
aSmithSummer\Roadblock\Model\Roadblock:
  expiry_interval: 0
  email_notify_frequency: 60
  email_notify_on_info: false
  email_notify_on_partial: false
  email_notify_on_blocked: false
  email_notify_on_latest: false
  email_notify_frequency_member: 60
  email_notify_on_info_member: false
  email_notify_on_partial_member: false
  email_notify_on_blocked_member: false
  email_notify_on_latest_member: false
aSmithSummer\Roadblock\Services\EmailService:
  email_from: test@example.com
  email_to: test@example.com
---
Name: request_log_job
---
aSmithSummer\Roadblock\Jobs\TruncateRequestLogJob:
  keep_log_period: 20160
  keep_log_repeat_interval: "+1 day"
