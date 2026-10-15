### `get_alert`

Get details of an alert

Route: `/api/v0/faults/:id`

Legacy alias: `/api/v0/alerts/:id`

- id is the alert id, you can obtain a list of alert ids from [`list_alerts`](#list_alerts).

Input:

  -

Example:

```curl
curl -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/faults/1
```

Output:

```json
{
 "status": "ok",
 "message": "",
 "count": 7,
 "alerts": [
  {
   "hostname": "localhost",
   "id": "1",
   "device_id": "1",
   "rule_id": "1",
   "state": "1",
   "alerted": "1",
   "open": "1",
   "timestamp": "2014-12-11 14:40:02"
  }]
}
```

### `ack_alert`

Acknowledge an alert

Route: `/api/v0/faults/:id`

Legacy alias: `/api/v0/alerts/:id`

- id is the alert id, you can obtain a list of alert ids from [`list_alerts`](#list_alerts).
- note is the note to add to the alert
- until_clear is a boolean. With the value false, the alert triggers
  again when it becomes worse, becomes better, or changes.

Input:

  -

Example:

```curl
curl -X PUT -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/faults/1
```

Output:

```json
{
 "status": "ok",
 "message": "",
 "message": "Alert has been acknowledged"
}
```

### `unmute_alert`

Unmute an alert

Route: `/api/v0/faults/unmute/:id`

Legacy alias: `/api/v0/alerts/unmute/:id`

- id is the alert id, you can obtain a list of alert ids from [`list_alerts`](#list_alerts).

Input:

  -

Example:

```curl
curl -X PUT -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/faults/unmute/1
```

Output:

```json
{
 "status": "ok",
 "message": "Alert has been unmuted"
}
```

### `list_alerts`

List all alerts

Route: `/api/v0/faults`

Legacy alias: `/api/v0/alerts`

Input:

- state: Filter the alerts by state, 0 = ok, 1 = alert, 2 = ack
- severity: Filter the alerts by severity. Valid values are `ok`, `warning`, `critical`.
- alert_rule: Filter alerts by alert rule ID.
- order: How to order the output, default is by timestamp
  (descending). Can be appended by DESC or ASC to change the order.

Examples:

```curl
curl -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/faults?state=1
```

```curl
curl -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/faults?severity=critical
```

```curl
curl -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/faults?order=timestamp%20ASC
```

```curl
curl -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/faults?alert_rule=49
```

Output:

```json
{
 "status": "ok",
 "message": "",
 "count": 1,
 "alerts": [
  {
   "id": "1",
   "device_id": "1",
   "rule_id": "1",
   "state": "1",
   "alerted": "1",
   "open": "1",
   "timestamp": "2014-12-11 14:40:02"
  }]
}
```

## Rules

#### Bounded correlation metadata profile

The collection routes `/api/v0/faults` and `/api/v0/alerts` accept the explicit
`profile=msp-correlation-v1&state=0,1` query. This profile returns current faults
in both states from one read-only, repeatable-read database transaction. It does
not read alert-log history or filter timestamps relative to the current time.
The ordinary response remains unchanged when `profile` is absent.

```curl
curl -H 'Authorization: Bearer YOURAPITOKENHERE' 'https://foo.example/api/v0/alerts?profile=msp-correlation-v1&state=0,1'
```

The profile accepts only those two query parameters, with those exact values,
on a collection route. An unknown profile, additional filter or single-fault
route returns HTTP 400 with code `CORRELATION_PROFILE_REQUEST`. It does not
silently ignore pagination or filtering parameters.

The success response has this shape:

```json
{
  "status": "ok",
  "contract": "msp-correlation-v1",
  "states": [0, 1],
  "consistency": "repeatable-read",
  "alerts": [
    {
      "id": 1,
      "device_id": 1,
      "rule_id": 1,
      "hostname": "localhost",
      "state": 1,
      "timestamp": "2014-12-11 14:40:02"
    }
  ],
  "count": 1,
  "complete": true
}
```

Only the six alert fields shown are returned, in ascending fault-ID order.
Timestamps retain their database representation. The query joins the same fault,
device and rule records as the ordinary collection, but does not select or
expand compressed details, rule procedures or notes. All three tables must use
InnoDB so every batch sees the same snapshot.

Database reads use batches of 256 rows. The whole response must fit within
65,536 rows and 16 MiB of encoded JSON, including the envelope. A resource limit,
invalid scalar or collection failure refuses the entire collection with HTTP
503 and one of `CORRELATION_COLLECTION_LIMIT`,
`CORRELATION_COLLECTION_INVALID` or `CORRELATION_COLLECTION_FAILED`.
The fixed error envelope contains `status: "error"`, the contract name,
`complete: false` and `code`; it never contains partial alerts or raw database
errors. These limits bound each batch and the complete response; they do not
promise unlimited historical scale.

The server finishes the collection and commits its read-only transaction before
sending success headers. Consumers must validate successful HTTP status, full
body completion, the contract, states, consistency, exact row count and
`complete: true` before acting on any record. A network interruption is a failed
collection. Do not fall back to a partial result or the ordinary details-rich
endpoint. Correlation consumers must preserve timestamp relationships between
active and recovered faults, including eligible older pairs.

### `get_alert_rule`

Get the alert rule details.

Route: `/api/v0/rules/:id`

- id is the rule id.

Input:

  -

Example:

```curl
curl -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/rules/1
```

Output:

```json
{
 "status": "ok",
 "message": "",
 "count": 1,
 "rules": [
  {
   "id": "1",
   "device_id": "1",
   "rule": "%devices.os != \"Juniper\"",
   "severity": "warning",
   "extra": "{\"invert\":false}",
   "default_operation_step_duration_seconds": 300,
   "alert_operation_id": 12,
   "operations": [
    {
      "id": 12,
      "name": "Default operation",
      "position": 0,
      "operation_phase": "problem",
      "escalation_step_from": 1,
      "escalation_step_to": 2,
      "start_in_seconds": 60,
      "step_duration_seconds": 0,
      "transports": [
       {"id": "3", "text": "Mail: NOC"},
       {"id": "g2", "text": "Group: On-call"}
      ]
    }
   ],
   "disabled": "0",
   "name": "A test rule"
  }
 ]
}
```

### `delete_rule`

Delete an alert rule by id

Route: `/api/v0/rules/:id`

- id is the rule id.

Input:

  -

Example:

```curl
curl -X DELETE -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/rules/1
```

Output:

```json
{
 "status": "ok",
 "message": "",
 "message": "Alert rule has been removed"
}
```

### `list_alert_rules`

List the alert rules.

Route: `/api/v0/rules`

  -

Input:

  -

Example:

```curl
curl -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/rules
```

Output:

```json
{
 "status": "ok",
 "message": "",
 "count": 1,
 "rules": [
  {
   "id": "1",
   "device_id": "-1",
   "rule": "%devices.os != \"Juniper\"",
   "severity": "critical",
   "extra": "{\"invert\":false}",
   "default_operation_step_duration_seconds": 300,
   "alert_operation_id": 12,
   "operations": [
    {
      "id": 12,
      "name": "Default operation",
      "position": 0,
      "operation_phase": "problem",
      "escalation_step_from": 1,
      "escalation_step_to": null,
      "start_in_seconds": 300,
      "step_duration_seconds": 0,
      "transports": [
       {"id": "3", "text": "Mail: NOC"}
      ]
    }
   ],
   "disabled": "0",
   "name": "A test rule"
  }]
}
```

### `add_rule`

Add a new alert rule.

Route: `/api/v0/rules`

  -

Input (JSON):

- devices: an array of device ids, or -1 for a global rule
- groups: Array of device group ids
- locations: Array of location ids
- invert_map: Optional boolean. When `true`, the rule applies to all
  devices except the selected devices, groups and locations.
- builder: the rule in the format `entity.condition value`. An example
  is `devices.status != 0` for the devices with a down mark. Encode it
  as JSON in the current storage format of the rules.
- severity: the severity level of the alert. The values are Ok, Warning, and Critical.
- disabled: the state of the rule. 0 is enabled and 1 is disabled
- default_operation_step_duration: Optional. When `alert_operation_id` is set, updates that **operation’s** default step duration (for example `5 m`). The value is stored on the global operation, not on the rule. When a segment’s `step_duration_seconds` is `0`, this default (or the global config default if unset) is used as the repeat interval.
- alert_operation_id: ID of a global alert operation (see **Alerts → Operations** in the UI), or `null` to suppress notifications for this rule
- operations: (optional) If `alert_operation_id` is not sent, a legacy array of operation objects is converted into a new global operation (with one **segment** per array element) and linked to the rule. Each segment has its own escalation range, timing, and transports.
  - operation_phase: `problem`, `recovery`, or `update`
  - escalation_step_from: 1-based escalation step start
  - escalation_step_to: escalation step end (`null` for no limit)
  - start_in_seconds: delay before first notification in this operation
  - step_duration_seconds: repeat interval in seconds (`0` means use the operation’s default step duration, or the global config default)
  - transports: required array of targets
    - single transport id: `3`
    - transport group id: `\"g2\"`
- invert: it inverts the check of the rule.
- name: the name of the rule. This field is mandatory.
- notes: your own notes about this rule
- proc: Optional. URL of a procedure/runbook for this rule, shown as a link on the alerts page and passed to transports. Must be `http://` or `https://` to be rendered, max 80 characters.

Example:

```curl
curl -X POST -d '{"devices":[1,2,3], "name":"testrule", "builder":{"condition":"AND","rules":[{"id":"devices.hostname","field":"devices.hostname","type":"string","input":"text","operator":"equal","value":"localhost"}],"valid":true}, "severity":"critical", "default_operation_step_duration":"5 m", "alert_operation_id": 12, "notes":"This a note from the API"}' -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/rules
```

Output:

```json
{
 "status": "ok"
}
```

### `edit_rule`

Edit an existing alert rule

Route: `/api/v0/rules`

  -

Input (JSON):

- rule_id: give the rule_id to edit an existing rule. Without this
  field, the API creates a new rule.
- devices: an array of device ids, or -1 for a global rule
- groups: Array of device group ids
- locations: Array of location ids
- invert_map: Optional boolean. When `true`, the rule applies to all
  devices except the selected devices, groups and locations.
- builder: the rule in the format `entity.condition value`. An example
  is `devices.status != 0` for the devices with a down mark. Encode it
  as JSON in the current storage format of the rules.
- severity: the severity level of the alert. The values are Ok, Warning, and Critical.
- disabled: the state of the rule. 0 is enabled and 1 is disabled
- default_operation_step_duration: Optional. When `alert_operation_id` is set, updates that **operation’s** default step duration (for example `5 m`). Stored on the operation, not the rule.
- alert_operation_id: ID of a global alert operation, or `null` to suppress notifications for this rule
- operations: (optional) Legacy array of operation objects; used only when `alert_operation_id` is not present in the request
  - operation_phase: `problem`, `recovery`, or `update`
  - escalation_step_from: 1-based escalation step start
  - escalation_step_to: escalation step end (`null` for no limit)
  - start_in_seconds: delay before first notification in this operation
  - step_duration_seconds: repeat interval in seconds (`0` means use the operation’s default step duration, or the global config default)
  - transports: required array of targets
    - single transport id: `3`
    - transport group id: `\"g2\"`
- invert: it inverts the check of the rule.
- name: the name of the rule. This field is mandatory.
- notes: your own notes about this rule
- proc: Optional. URL of a procedure/runbook for this rule, shown as a link on the alerts page and passed to transports. Must be `http://` or `https://` to be rendered, max 80 characters.

Example:

```curl
curl -X PUT -d '{"rule_id":1,"devices":["-1"], "name":"testrule", "builder":{"condition":"AND","rules":[{"id":"devices.hostname","field":"devices.hostname","type":"string","input":"text","operator":"equal","value":"localhost"}],"valid":true}, "severity":"critical", "default_operation_step_duration":"5 m", "alert_operation_id": 12, "notes":"This a note from the API"}' -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/rules
```

Output:

```json
{
 "status": "ok"
}
```

## Alert templates

### `get_alert_template`

Get the alert template details.

Route: `/api/v0/alert_templates/:id`



Input:

  - id: (Required) is the alert template id.

Example:

```curl
curl -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/alert_templates/1
```

Output:

```json
{
  "status": "ok",
  "alert_templates": [
    {
      "id": 4,
      "name": "Default Alert Template",
      "template": "{{ $alert->title }}\nSeverity: {{ $alert->severity }}\n@if ($alert->state == 0)Time elapsed: {{ $alert->elapsed }} @endif\nTimestamp: {{ $alert->timestamp }}\nUnique-ID: {{ $alert->uid }}\nRule: @if ($alert->name) {{ $alert->name }} @else {{ $alert->rule }} @endif\n@if ($alert->faults) Faults:\n@foreach ($alert->faults as $key => $value)\n  #{{ $key }}: {{ $value['string'] }}\n@endforeach\n@endif\nAlert sent to:\n@foreach ($alert->contacts as $key => $value)\n  {{ $value }} <{{ $key }}>\n@endforeach",
      "title": null,
      "title_rec": null,
      "alert_rules": []
    },
  ],
  "count": 1
}
```

### `list_alert_templates`

List the alert templates.

Route: `/api/v0/alert_templates`


Input: None

Example:

```curl
curl -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/alert_templates
```

Output:

```json
{
  "status": "ok",
  "alert_templates": [
    {
      "id": 4,
      "name": "Default Alert Template",
      "template": "{{ $alert->title }}\nSeverity: {{ $alert->severity }}\n@if ($alert->state == 0)Time elapsed: {{ $alert->elapsed }} @endif\nTimestamp: {{ $alert->timestamp }}\nUnique-ID: {{ $alert->uid }}\nRule: @if ($alert->name) {{ $alert->name }} @else {{ $alert->rule }} @endif\n@if ($alert->faults) Faults:\n@foreach ($alert->faults as $key => $value)\n  #{{ $key }}: {{ $value['string'] }}\n@endforeach\n@endif\nAlert sent to:\n@foreach ($alert->contacts as $key => $value)\n  {{ $value }} <{{ $key }}>\n@endforeach",
      "title": null,
      "title_rec": null,
      "alert_rules": []
    },
  ],
  "count": 1
}
```

### `add_alert_template`

Add a new alert template.

Route: `/api/v0/alert_templates`

Input (JSON):

- name: (Required) Name for the new template
- template: (Required) Template code used to generate the alert message
- title: Title that is used when an alert is generated
- title_rec: Title that is used when an alert has recovered
- alert_rules: an array of rule_id values for this template. See also
  [`list_alert_rules`](#list_alert_rules).

Example:

```curl
curl -X POST -d '{"name":"new alert template","template":"---","title":"CREATED ALERT","title_rec": "ALERT RECOVERED","alert_rules":[]}' -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/alert_templates
```

Output:
- status: Status of the request. Can be: ok, warning, error
- message: the output of this call. It also holds the error messages.
- id: The id of the newly created alert template

```json
{
  "status": "ok",
  "message": "Alert template has been created and attached rules have been updated.",
  "id": 2
}
```

### `edit_rule`

Edit an existing alert rule

Route: `/api/v0/alert_templates`

Input (JSON):

- name: (Required) Name for the new template
- template: (Required) Template code used to generate the alert message
- template_id: (Required) the id of the template to change. Without
  this field, the API creates a new alert template.
- title: Title that is used when an alert is generated
- title_rec: Title that is used when an alert has recovered
- alert_rules: an array of rule_id values for this template. See also
  [`list_alert_rules`](#list_alert_rules).

Example:

```curl
curl -X POST -d '{"name":"new alert template","template":"---","template_id":"2","title":"CREATED ALERT","title_rec": "ALERT RECOVERED","alert_rules":[]}' -H 'Authorization: Bearer YOURAPITOKENHERE' https://foo.example/api/v0/alert_templates
```

Output:

```json
{
  "status": "ok",
  "message": "Alert template has been updated and attached rules have been updated.",
  "id": 2
}
```
