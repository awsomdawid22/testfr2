-- ============================================================
-- LAE Forum Migration: v1.0 → v1.1
-- Run this on EXISTING installations only.
-- New installs: use database.sql directly.
-- ============================================================

-- 1. Add thread_type and link columns to threads table
ALTER TABLE threads 
    ADD COLUMN thread_type ENUM('thread','application','appeal') DEFAULT 'thread' AFTER user_id,
    ADD COLUMN application_id INT DEFAULT NULL AFTER thread_type,
    ADD COLUMN appeal_id INT DEFAULT NULL AFTER application_id;

-- 2. Tag existing threads in the applications category
UPDATE threads t
JOIN categories c ON t.category_id = c.id AND c.slug = 'applications'
SET t.thread_type = 'application'
WHERE t.thread_type = 'thread';

-- 3. Tag existing threads in the appeals category
UPDATE threads t
JOIN categories c ON t.category_id = c.id AND c.slug = 'appeals'
SET t.thread_type = 'appeal'
WHERE t.thread_type = 'thread';

-- 4. Replace the old minimal rules with the full LAE Community Rules
DELETE FROM rules;

INSERT INTO rules (category, rule_number, title, content, sort_order) VALUES

-- Community Standards
('Community Standards', 'R1', 'Respect for All Members', 'Treat all members with respect at all times. Any disrespectful or hostile behavior will not be tolerated and may lead to disciplinary action.', 1),
('Community Standards', 'R2', 'Follow Instructions from Staff', 'Supervisors, desk services, and community leaders are here to ensure the smooth operation of the server. Adhere to their instructions, and if you have concerns, respectfully gather evidence and submit a ticket for review.', 2),
('Community Standards', 'R3', 'Be Courteous in Communications', 'Always use polite language and maintain a respectful tone in all interactions. Disrespectful or abusive language will result in warnings or more severe disciplinary measures.', 3),
('Community Standards', 'R4', 'Assist in Processing Your Ticket', 'When seeking help, be clear and concise, and refrain from spamming requests. Respect the time and effort of everyone involved.', 4),
('Community Standards', 'R5', 'Respect Decisions from Leadership', 'The server owner and appointed administrators have the final say in server matters. Feedback and suggestions are welcome, but their decisions are made with the best interest of the community in mind.', 5),
('Community Standards', 'R6', 'Constructive Criticism Only', 'If you disagree with any decisions, express your concerns constructively. Personal attacks and inappropriate comments are strictly prohibited.', 6),
('Community Standards', 'R7', 'No Harassment', 'Harassing or bullying members, either publicly or privately, is unacceptable and will result in immediate disciplinary action.', 7),
('Community Standards', 'R8', 'Report Issues Appropriately', 'For any issues, use the designated channels such as Support chat, the ticket system, or DMs for confidential matters. Avoid making public accusations or airing grievances publicly.', 8),

-- Section 1
('Section 1 — Community Requirements', '1.1', 'Age Requirement', 'All applicants must be at least thirteen (13) years of age to join and participate in The Los Angeles Experience, in accordance with Discord Terms of Service. Members in countries with higher minimum age requirements must comply with their local age restriction. Anyone found to be under the minimum required age will be removed without exception.', 10),
('Section 1 — Community Requirements', '1.2', 'Technical Requirements', 'All members must own a legitimate, non-pirated copy of Grand Theft Auto V, have a working microphone with clear audio quality, and be able to fluently speak, read, and understand English to ensure effective communication across all platforms.', 11),
('Section 1 — Community Requirements', '1.3', 'Platform & Software Requirements', 'All members must have access to and reliably operate FiveM and Discord. Members must maintain a stable internet connection capable of supporting in-game participation and voice communication.', 12),
('Section 1 — Community Requirements', '1.4', 'Behavioral Standards', 'All members must treat one another with basic respect regardless of rank, role, background, or personal opinions. Disrespectful behavior, harassment, and discrimination will not be tolerated on any platform.', 13),
('Section 1 — Community Requirements', '1.5', 'Application and Membership Compliance', 'By submitting an application, all members acknowledge and agree to comply with all rules and policies. Continued membership is contingent upon consistent adherence to these standards.', 14),
('Section 1 — Community Requirements', '1.6', 'Privacy and Confidentiality', 'Members must respect the privacy of others. Strictly prohibited: sharing personal information without consent, doxxing, sharing private DM recordings, posting someone''s media without permission, and impersonating another member.', 15),
('Section 1 — Community Requirements', '1.7', 'Community Compliance and Updates', 'All policies are subject to change at the discretion of Community Management. It is each member''s responsibility to stay informed. Ignorance of an updated rule is not a valid excuse.', 16),

-- Section 1.4 Language
('Section 1.4 — Language Policy', '1.4.1', 'Prohibited Language', 'Zero-tolerance for: sexual violence / pedophilia references (any mention of rape, molestation, or sexual acts involving minors), and encouragement of self-harm or suicide ("Kill yourself," "KYS"). Bypassing filters using alternate spelling is still a violation. "Dark humor" or "private VC context" are not valid excuses. Staff may act based on tone, frequency, and context even if specific words are not listed.', 17),
('Section 1.4 — Language Policy', '1.4.2', 'NSFW, Harassment & Inappropriate Content', 'Sexual harassment is not tolerated: unwanted sexual jokes, remarks about another member''s body, repeated flirting that makes others uncomfortable, pressuring for sexual interaction, mocking gender/sexual identity, and graphic discussions in public channels. Targeted harassment (bullying, ganging up, public callouts to embarrass, weaponizing memes) is strictly prohibited. "It was just a joke" or "they didn''t complain" is not a valid defense.', 18),

-- Section 2
('Section 2 — Member Conduct', '2.1', 'Respectful Interaction', 'All members must treat others with dignity and professionalism. Prohibited: harassment, bullying, discrimination based on protected characteristics, hostile conduct, repeated inappropriate jokes at specific individuals, and mocking or provoking others. Disagreements must be handled with maturity — keep disputes civil and avoid public arguments.', 20),
('Section 2 — Member Conduct', '2.2', 'Communication Standards', 'Excessive profanity is discouraged. Racist, homophobic, sexually explicit, or intentionally offensive language is strictly prohibited. Voice chat rules: use push-to-talk, avoid mic spamming, respect when others request quiet.', 21),
('Section 2 — Member Conduct', '2.3', 'Zero Tolerance — Harassment & Discrimination', 'Zero Tolerance for harassment, discrimination, or targeted abuse. Prohibited: racial/ethnic discrimination, slurs, gender-based harassment, sexual harassment, cyber bullying, intimidation tactics. Applies across all LAE platforms. Clear and intentional violations will receive no warnings.', 22),
('Section 2 — Member Conduct', '2.4', 'Roleplay Conduct', 'All RP must reflect believable, grounded actions. Prohibited: reckless driving without IC justification, unrealistic criminal behaviour, powergaming, combat logging, RDM/VDM. IC and OOC must remain clearly separate. Never use OOC knowledge to influence IC decisions (metagaming).', 23),
('Section 2 — Member Conduct', '2.5', 'Chain of Command Compliance', 'All members must respect the established Chain of Command. Direct questions and concerns to the appropriate level of leadership. Jumping the chain without valid reason may result in delays or dismissal.', 24),
('Section 2 — Member Conduct', '2.6', 'Impersonation & Misuse of Authority', 'Members may not impersonate another user, staff member, or role. False claims of elevated permissions result in immediate disciplinary action. Staff must exercise their roles fairly — misuse of administrative tools, favoritism, or using authority to intimidate others is strictly prohibited.', 25),
('Section 2 — Member Conduct', '2.7', 'Accountability & Integrity', 'Members must be honest during all staff-led investigations. Violations: lying to staff, withholding facts, tampering with logs, encouraging others to lie. Members found dishonest will face more severe action than if they had been truthful.', 26),
('Section 2 — Member Conduct', '2.8', 'Reporting Violations', 'All reports must be submitted through the official support ticket system. Include screenshots, clips, or timestamps. False reports will result in disciplinary action. Retaliating against a member for a good-faith report is strictly prohibited.', 27),

-- Section 3
('Section 3 — Community Organization', '3.1', 'Operational Structure', 'Hierarchy: (1) Community President, (2) Community Lead/Manager, (3) Staff Lead. Administrative roles: Administration, Senior Moderation, Moderation, and Support (entry-level). Each role has defined responsibilities within the community''s operations.', 30),
('Section 3 — Community Organization', '3.2', 'Chain of Command', 'All members must follow the established Chain of Command. Final authority rests with the highest-ranking active staff member involved in a matter. Failure to respect the CoC may result in delays or disciplinary action.', 31),
('Section 3 — Community Organization', '3.3', 'Applications & Promotions', 'The Los Angeles Experience uses a merit-based progression system. Applications are reviewed based on staffing needs, conduct, and community engagement. Promotions are earned through demonstrated merit, professionalism, and consistent activity.', 32),

-- Section 4
('Section 4 — Roleplay Regulations', '4.1', 'General Roleplay Standards', 'RP must reflect real-world logic. Violations: FailRP, Metagaming, Powergaming, RDM/VDM, GTA-Style Driving, NITRP (no intent to RP), Combat Logging, RevengeRP (returning after death to retaliate), Baiting (provoking without IC justification), Abuse of Audio (soundboard disruption). Always separate IC and OOC conversations.', 40),
('Section 4 — Roleplay Regulations', '4.2', 'Character Rules & New Life Rule', 'New Life Rule (NLR): When a character dies, they lose all memory of events leading up to death. You may not return to the scene, seek revenge, or use information from the previous life. Characters must have realistic backgrounds and consistent behaviour. Throwaway characters used for troll behaviour will be subject to administrative review.', 41),
('Section 4 — Roleplay Regulations', '4.3', 'Area of Patrol (AOP)', 'All members must remain within the currently designated AOP during active RP unless directed otherwise by staff. AOP is set by staff and may be adjusted based on server population or ongoing scenarios. Unauthorized relocation outside the AOP is not permitted.', 42),
('Section 4 — Roleplay Regulations', '4.4', 'Law Enforcement & Civilian Interaction', 'Civilians must comply with reasonable commands from law enforcement unless they have a valid IC reason not to. Randomly fleeing or resisting without RP justification is FailRP. Civilians may not impersonate law enforcement, fire, EMS, or emergency services without staff approval. The /911 system is for in-character reporting only.', 43),
('Section 4 — Roleplay Regulations', '4.5', 'Prohibited Scenarios', 'PROHIBITED without exception: Sexual Roleplay (any form), School Shootings or Education-Based Violence, Unauthorized impersonation of Government/Public Services. RESTRICTED (requires prior staff approval): Terrorism/Mass Casualty Events, Sensitive or Disturbing Themes (excessive gore, mental health crises, hostage executions). Engaging in restricted scenarios without approval results in immediate administrative action.', 44),
('Section 4 — Roleplay Regulations', '4.6', 'Scene Management', 'Leaving a scene without IC resolution to avoid consequences is Combat Logging and strictly prohibited. If you must leave due to emergency, notify involved parties immediately. If you crash during an active scene, return as soon as possible. Deliberate disruption or evasion results in administrative action.', 45),
('Section 4 — Roleplay Regulations', '4.7', 'Priority Rules', 'Use /prio-start before initiating any priority. Staff approval may be required. Back-to-back priorities from the same player/group are not permitted. Use /prio-stop after a priority ends — a cooldown follows. Every priority must have a realistic backstory. Priorities for chaos or trolling will be shut down immediately.', 46),
('Section 4 — Roleplay Regulations', '4.7a', 'Water Evasion Guidelines', 'Characters may remain underwater for no more than 10–20 seconds without a valid IC justification (e.g., scuba gear). Water evasions should not exceed 5–10 minutes. Circling endlessly, exploiting terrain, or avoiding capture through unrealistic movement will result in staff intervention.', 47),
('Section 4 — Roleplay Regulations', '4.8', 'Booster Vehicle Policy', 'Booster vehicles are a privilege monitored by staff at all times. All driving must be realistic and immersion-friendly. Reckless or stunt-based driving is prohibited. Booster vehicles may not be used in priority situations. Misuse results in permanent loss of access with no appeal.', 48),
('Section 4 — Roleplay Regulations', '4.9', 'In-Game Chats (/ooc, /gme, /me)', '/ooc: for brief clarifications, sound checks, confirming lag. Prohibited: rude remarks, arguing, drama, spamming. /gme (Global Me): for scene-wide civilian updates. Not for casual OOC discussion. /me (Local Me): for describing physical actions. Not for advertising or powergaming (initiating actions without giving others time to respond).', 49),
('Section 4 — Roleplay Regulations', '4.10', 'Law Enforcement Standards', 'LEOs must maintain professionalism and integrity. Corruption is prohibited unless approved as an authorized RP storyline. Resources must match incident scale. Code 3 must reflect a real emergency. Mandatory radio call-ins: en route, on scene, and clear. When a dispatcher is online in CAD, do not self-attach to calls. Always clear your call once a scene concludes.', 50),
('Section 4 — Roleplay Regulations', '4.11', 'Civilian Roleplay Standards', 'All civilian characters must have a realistic name, consistent behaviour, and believable backstory. Passive roles (Postal Worker, Taxi Driver, Waste Management, Airline Pilot, Construction Worker, City Maintenance) are restricted to non-criminal gameplay. Criminal RP must be justified, proportionate, and RP-driven. All civilians must have characters, vehicles, and registered firearms in CAD before active RP.', 51),

-- Section 5
('Section 5 — Media Policy', '5.1', 'General Media Guidelines', 'All members may create and share media consistent with community values. Do not record or publish content involving private or administrative discussions without prior permission. Media should reflect fair, respectful, and immersive gameplay.', 60),
('Section 5 — Media Policy', '5.2', 'Media Content Guidelines', 'Media must center on in-game scenarios. Do not include admin tools, staff panels, internal discussions, or confidential material without written approval from a Community Manager or higher. Content must not include inappropriate language, harassment, bullying, gratuitous non-RP violence, or misleading thumbnails that misrepresent the community.', 61),

-- Section 6
('Section 6 — Disciplinary Procedures', '6.1', 'Types of Disciplinary Actions', 'Actions: (1) Verbal Warning, (2) Written Warning, (3) Temporary Ban, (4) Permanent Ban. ESCALATION RULE: Any player who receives 3 bans of any kind will be permanently banned. Any player who accumulates 4 warnings of any kind will also be permanently banned, regardless of the nature of the most recent offense.', 70),
('Section 6 — Disciplinary Procedures', '6.2', 'Investigation Procedures', 'Report violations via the #tickets channel. A staff member reviews the report and escalates if sufficient cause exists. Evidence collected includes chat logs, recordings, and witness statements. The member involved is given an opportunity to share their side. All investigations are handled confidentially.', 71),
('Section 6 — Disciplinary Procedures', '6.3', 'Appeals Process', 'Appeals must be submitted within 7 days of the disciplinary action. Include all relevant evidence. A staff member not involved in the original investigation will re-review the case. The administration team will issue a final decision (uphold, modify, or overturn). All appeal decisions are final.', 72),
('Section 6 — Disciplinary Procedures', '6.4', 'Code of Conduct During Investigations', 'Members must be truthful at all times during investigations. Members not directly involved must not attempt to influence or interfere. Comply with staff instructions and participate constructively.', 73),
('Section 6 — Disciplinary Procedures', '6.5', 'Non-Retaliation Policy', 'Retaliation against any member who submits a good-faith report, cooperates with an investigation, or serves as a witness is strictly prohibited. Retaliation includes threats, harassment, social exclusion, or retaliatory reporting. Counter-reports filed more than 48 hours after the original report, or with fabricated violations, will be treated as retaliation.', 74);

-- 5. Add thread_id column to applications if missing
ALTER TABLE applications ADD COLUMN thread_id INT DEFAULT NULL AFTER additional_info;
