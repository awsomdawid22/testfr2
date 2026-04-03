-- ============================================
-- Los Angeles Experience FiveM Forum Database
-- ============================================

CREATE DATABASE IF NOT EXISTS lae_forum CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lae_forum;

-- Roles
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    color VARCHAR(7) DEFAULT '#ffffff',
    badge_color VARCHAR(7) DEFAULT '#1a1a2e',
    animation VARCHAR(50) DEFAULT NULL,
    priority INT DEFAULT 0,
    can_post TINYINT(1) DEFAULT 1,
    can_create_threads TINYINT(1) DEFAULT 1,
    can_moderate TINYINT(1) DEFAULT 0,
    can_admin TINYINT(1) DEFAULT 0,
    can_ban TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default roles
INSERT INTO roles (name, display_name, color, badge_color, priority, can_post, can_create_threads, can_moderate, can_admin, can_ban) VALUES
('owner', 'Owner', '#ff4500', '#1a0800', 100, 1, 1, 1, 1, 1),
('co_owner', 'Co-Owner', '#ff6b00', '#1a0d00', 90, 1, 1, 1, 1, 1),
('head_admin', 'Head Admin', '#ff2d55', '#1a0010', 80, 1, 1, 1, 1, 1),
('admin', 'Administrator', '#e91e8c', '#150012', 70, 1, 1, 1, 1, 0),
('senior_mod', 'Senior Moderator', '#9b59b6', '#0d0010', 60, 1, 1, 1, 0, 0),
('moderator', 'Moderator', '#3498db', '#001525', 50, 1, 1, 1, 0, 0),
('senior_dev', 'Senior Developer', '#00b894', '#001510', 45, 1, 1, 0, 0, 0),
('developer', 'Developer', '#00cec9', '#001515', 40, 1, 1, 0, 0, 0),
('support', 'Support Staff', '#1abc9c', '#001410', 38, 1, 1, 0, 0, 0),
('police_chief', 'Police Chief', '#4a90d9', '#001020', 35, 1, 1, 0, 0, 0),
('ems_chief', 'EMS Chief', '#e74c3c', '#1a0000', 35, 1, 1, 0, 0, 0),
('vip_plus', 'VIP+', '#f39c12', '#1a1000', 25, 1, 1, 0, 0, 0),
('vip', 'VIP', '#f1c40f', '#1a1500', 20, 1, 1, 0, 0, 0),
('trusted', 'Trusted Member', '#2ecc71', '#001500', 15, 1, 1, 0, 0, 0),
('member', 'Member', '#95a5a6', '#111111', 10, 1, 1, 0, 0, 0),
('new_member', 'New Member', '#7f8c8d', '#0d0d0d', 5, 1, 0, 0, 0, 0),
('banned', 'Banned', '#c0392b', '#0a0000', 0, 0, 0, 0, 0, 0);

-- Users
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT DEFAULT 14,
    avatar VARCHAR(500) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    discord_id VARCHAR(100) DEFAULT NULL,
    steam_hex VARCHAR(100) DEFAULT NULL,
    post_count INT DEFAULT 0,
    is_banned TINYINT(1) DEFAULT 0,
    ban_reason TEXT DEFAULT NULL,
    ban_expires TIMESTAMP NULL DEFAULT NULL,
    last_seen TIMESTAMP NULL DEFAULT NULL,
    email_verified TINYINT(1) DEFAULT 0,
    verification_token VARCHAR(100) DEFAULT NULL,
    reset_token VARCHAR(100) DEFAULT NULL,
    reset_expires TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- Categories
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    slug VARCHAR(100) NOT NULL UNIQUE,
    icon VARCHAR(50) DEFAULT 'fas fa-folder',
    color VARCHAR(7) DEFAULT '#e91e8c',
    sort_order INT DEFAULT 0,
    parent_id INT DEFAULT NULL,
    min_role_id INT DEFAULT 14,
    is_visible TINYINT(1) DEFAULT 1,
    thread_count INT DEFAULT 0,
    post_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (min_role_id) REFERENCES roles(id)
);

-- Default categories
INSERT INTO categories (name, description, slug, icon, color, sort_order) VALUES
('Announcements', 'Official server announcements and updates', 'announcements', 'fas fa-bullhorn', '#ff4500', 1),
('General Discussion', 'Talk about anything related to LAE', 'general', 'fas fa-comments', '#e91e8c', 2),
('Introductions', 'Introduce yourself to the community', 'introductions', 'fas fa-hand-wave', '#9b59b6', 3),
('Server Updates', 'Patch notes and server changes', 'updates', 'fas fa-code-branch', '#3498db', 4),
('Guides & Tutorials', 'Helpful guides for new and existing players', 'guides', 'fas fa-book', '#00b894', 5),
('Bug Reports', 'Report server bugs and issues', 'bugs', 'fas fa-bug', '#e74c3c', 6),
('Suggestions', 'Suggest features and improvements', 'suggestions', 'fas fa-lightbulb', '#f39c12', 7),
('Staff Applications', 'Apply for staff positions', 'applications', 'fas fa-file-alt', '#00cec9', 8),
('Ban Appeals', 'Appeal your ban or report a player', 'appeals', 'fas fa-gavel', '#fd79a8', 9),
('Media & Screenshots', 'Share your screenshots and videos', 'media', 'fas fa-camera', '#6c5ce7', 10),
('Off Topic', 'Anything goes (within rules)', 'offtopic', 'fas fa-random', '#74b9ff', 11);

-- Threads
CREATE TABLE threads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(300) NOT NULL,
    category_id INT NOT NULL,
    user_id INT NOT NULL,
    thread_type ENUM('thread','application','appeal') DEFAULT 'thread',
    application_id INT DEFAULT NULL,
    appeal_id INT DEFAULT NULL,
    is_pinned TINYINT(1) DEFAULT 0,
    is_locked TINYINT(1) DEFAULT 0,
    is_hidden TINYINT(1) DEFAULT 0,
    views INT DEFAULT 0,
    reply_count INT DEFAULT 0,
    last_reply_user_id INT DEFAULT NULL,
    last_reply_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (last_reply_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Posts
CREATE TABLE posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    thread_id INT NOT NULL,
    user_id INT NOT NULL,
    content LONGTEXT NOT NULL,
    is_hidden TINYINT(1) DEFAULT 0,
    edited_at TIMESTAMP NULL DEFAULT NULL,
    edited_by INT DEFAULT NULL,
    likes INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Post Likes
CREATE TABLE post_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_like (post_id, user_id),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Staff Applications
CREATE TABLE applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    position VARCHAR(100) NOT NULL,
    age INT,
    timezone VARCHAR(50),
    hours_available INT,
    previous_experience TEXT,
    why_apply TEXT,
    scenario_answer TEXT,
    additional_info TEXT,
    thread_id INT DEFAULT NULL,
    status ENUM('pending','reviewing','accepted','denied') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    review_notes TEXT,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Ban Appeals
CREATE TABLE ban_appeals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ban_reason TEXT,
    appeal_reason TEXT NOT NULL,
    status ENUM('pending','reviewing','accepted','denied') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    review_notes TEXT,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Private Messages
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    subject VARCHAR(255),
    content TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Notifications
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255),
    content TEXT,
    link VARCHAR(500),
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Audit Log
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50),
    target_id INT,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Sessions
CREATE TABLE sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Server Rules
CREATE TABLE rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(100) NOT NULL,
    rule_number VARCHAR(10) NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    sort_order INT DEFAULT 0
);

-- Insert sample admin user (password: Admin@123)
INSERT INTO users (username, email, password_hash, role_id, email_verified) VALUES
('LAE_Admin', 'admin@laexperience.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 1);

-- ============================================
-- LAE Community Rules & Regulations (v1.4)
-- ============================================
DELETE FROM rules;

INSERT INTO rules (category, rule_number, title, content, sort_order) VALUES

-- Community Standards (Header rules)
('Community Standards', 'R1', 'Respect for All Members', 'Treat all members with respect at all times. Any disrespectful or hostile behavior will not be tolerated and may lead to disciplinary action.', 1),
('Community Standards', 'R2', 'Follow Instructions from Staff', 'Supervisors, desk services, and community leaders are here to ensure the smooth operation of the server. Adhere to their instructions, and if you have concerns, respectfully gather evidence and submit a ticket for review.', 2),
('Community Standards', 'R3', 'Be Courteous in Communications', 'Always use polite language and maintain a respectful tone in all interactions. Disrespectful or abusive language will result in warnings or more severe disciplinary measures.', 3),
('Community Standards', 'R4', 'Assist in Processing Your Ticket', 'When seeking help, be clear and concise, and refrain from spamming requests. Respect the time and effort of everyone involved.', 4),
('Community Standards', 'R5', 'Respect Decisions from Leadership', 'The server owner and appointed administrators have the final say in server matters. Feedback and suggestions are welcome, but their decisions are made with the best interest of the community in mind.', 5),
('Community Standards', 'R6', 'Constructive Criticism Only', 'If you disagree with any decisions, express your concerns constructively. Personal attacks and inappropriate comments are strictly prohibited.', 6),
('Community Standards', 'R7', 'No Harassment', 'Harassing or bullying members, either publicly or privately, is unacceptable and will result in immediate disciplinary action.', 7),
('Community Standards', 'R8', 'Report Issues Appropriately', 'For any issues, use the designated channels such as Support chat, the ticket system, or DMs for confidential matters. Avoid making public accusations or airing grievances publicly to maintain a positive community environment.', 8),

-- Section 1: Community Requirements
('Section 1 — Community Requirements', '1.1', 'Age Requirement', 'All applicants must be at least thirteen (13) years of age to join and participate in The Los Angeles Experience, in accordance with Discord Terms of Service. Members in countries with higher minimum age requirements must comply with their local age restriction. Anyone found to be under the minimum required age will be removed without exception.', 10),
('Section 1 — Community Requirements', '1.2', 'Technical Requirements', 'All members must own a legitimate, non-pirated copy of Grand Theft Auto V, have a working microphone with clear audio quality, and be able to fluently speak, read, and understand English to ensure effective communication across all platforms.', 11),
('Section 1 — Community Requirements', '1.3', 'Platform & Software Requirements', 'All members must have access to and reliably operate FiveM (used to access the game server) and Discord (the primary communication platform). Members must maintain a stable internet connection capable of supporting both in-game participation and voice communication.', 12),
('Section 1 — Community Requirements', '1.4', 'Behavioral Standards', 'All members must treat one another with basic respect regardless of rank, role, background, or personal opinions. Disrespectful behavior, harassment, and discrimination based on race, gender, religion, orientation, or background will not be tolerated. This applies to all platforms — in-game, Discord, and beyond.', 13),
('Section 1 — Community Requirements', '1.5', 'Application and Membership Compliance', 'By submitting an application to join The Los Angeles Experience, all members acknowledge and agree to comply with all rules, regulations, and policies. Continued membership is contingent upon consistent adherence to these standards.', 14),
('Section 1 — Community Requirements', '1.6', 'Privacy and Confidentiality', 'Members must respect the privacy of others. Strictly prohibited: sharing personal information without consent, doxxing, sharing private DM recordings without approval, posting someone''s face or media without permission, and impersonating another member. Staff may screenshot or log relevant behavior for moderation purposes only.', 15),
('Section 1 — Community Requirements', '1.7', 'Community Compliance and Updates', 'All policies are subject to change at the discretion of Community Management. It is each member''s responsibility to stay up to date with changes. Ignorance of an updated rule is not a valid excuse for violating it. Changes will be announced in the #server-updates channel.', 16),

-- Section 1.4.1: Prohibited Language
('Section 1.4 — Language Policy', '1.4.1', 'Prohibited Language Notice', 'The following types of language are strictly prohibited at all times regardless of context, tone, or intent: (1) Sexual Violence / Pedophilia References — any mention or joke involving rape, molestation, or sexual acts involving minors. (2) Encouragement of Self-Harm or Suicide — including "Kill yourself," "KYS," or similar phrases. Bypassing filters using alternate spelling or symbols is still a violation. "Dark humor," "inside jokes," or "private VC context" are not valid excuses.', 17),
('Section 1.4 — Language Policy', '1.4.2', 'NSFW, Harassment & Inappropriate Content', 'Sexual harassment creates a hostile environment and is not tolerated. This includes: unwanted sexual jokes or innuendos, remarks about another member''s body or appearance, repeated flirting that makes others uncomfortable, pressuring someone for sexual interaction, mocking gender/sexual identity, and graphic discussions in public channels. Targeted harassment — bullying, ganging up, public callouts to embarrass, weaponizing memes to attack — is also strictly prohibited. "It was just a joke" or "they didn''t complain" is not a valid defense.', 18),

-- Section 2: Member Conduct
('Section 2 — Member Conduct', '2.1', 'Respectful Interaction', 'All members must treat others with dignity and professionalism. Prohibited: harassment, bullying, discrimination based on protected characteristics, hostile or passive-aggressive conduct, repeated inappropriate jokes at specific individuals, mocking or intentionally provoking others. Disagreements must be handled with maturity — keep disputes civil, avoid public arguments, do not retaliate or gossip.', 20),
('Section 2 — Member Conduct', '2.2', 'Communication Standards', 'Excessive profanity is discouraged and may be moderated at staff discretion. Racist, homophobic, sexually explicit, or intentionally offensive language is strictly prohibited. In voice chats: use push-to-talk or noise suppression, avoid mic spamming or yelling, respect when others request quiet, do not stream NSFW content without proper opt-in.', 21),
('Section 2 — Member Conduct', '2.3', 'Harassment and Discrimination', 'Zero Tolerance Policy for any form of harassment, discrimination, or targeted abuse. Prohibited: racial/ethnic discrimination, slurs and stereotypes, gender-based harassment, sexual harassment, cyber bullying, passive-aggressive targeting, intimidation tactics. This policy applies across all LAE platforms: Discord text/voice, in-game chat/voice, and screen sharing. Clear and intentional violations — especially hate speech or sexual harassment — will receive no warnings.', 22),
('Section 2 — Member Conduct', '2.4', 'Roleplay Conduct', 'All RP must reflect believable, grounded actions. Prohibited: reckless driving without IC justification, unrealistic criminal behavior (e.g., robbing 5 banks in a row), powergaming, combat logging, RDM/VDM. IC and OOC must remain clearly separate. Never use OOC knowledge to influence IC decisions (metagaming). Do not use IC chat for OOC frustration.', 23),
('Section 2 — Member Conduct', '2.5', 'Chain of Command Compliance', 'All members must respect the established Chain of Command. Direct questions, requests, or concerns to the appropriate level of leadership. Jumping the chain without valid reason may result in delays or dismissal. Members must accept and follow leadership decisions unless directed otherwise by a higher-ranking authority or unless the decision violates documented rules.', 24),
('Section 2 — Member Conduct', '2.6', 'Impersonation & Misuse of Authority', 'Members may not impersonate another user, staff member, or role. False claims of elevated permissions or rank result in immediate disciplinary action. Staff must exercise their roles fairly — misuse of administrative tools, unjustified punishments, favoritism, selective enforcement, or using authority to intimidate others is strictly prohibited.', 25),
('Section 2 — Member Conduct', '2.7', 'Accountability & Integrity', 'Members must be honest and cooperative during all staff-led investigations. Violations: lying to staff, withholding relevant facts, tampering with messages or logs, encouraging others to lie. Members found dishonest during an investigation will face more severe action than if they had been truthful. Each member is accountable for their behavior in all community spaces, including private DMs when related to community operations.', 26),
('Section 2 — Member Conduct', '2.8', 'Reporting Violations', 'All reports must be submitted through the official complaint or support ticket system on Discord. Reports should include screenshots, clips, or timestamps when available. False or malicious reports will result in disciplinary action. Retaliating against a member for submitting a good-faith report is strictly prohibited — retaliation includes harassment, exclusion, threats, or any form of backlash.', 27),

-- Section 2.3.1: Harassment and Discrimination detail
('Section 2.3 — Zero Tolerance', '2.3.1', 'Harassment and Discrimination Detail', 'Prohibited behaviors: racial/ethnic/cultural discrimination, use of slurs and stereotypes, gender-based harassment (misogynistic, sexist, or transphobic language), sexual harassment (unwanted jokes, NSFW content directed at members, uncomfortable sexual commentary), and cyber harassment/bullying (ganging up, public shaming, spreading rumors, intimidation tactics including threatening to leak personal info). Violations result in immediate disciplinary action. No warnings for clear and intentional violations involving hate speech or sexual harassment.', 28),

-- Section 3: Community Organization
('Section 3 — Community Organization', '3.1', 'Operational Structure', 'Community leadership hierarchy: (1) Community President — oversees entire community, final decision-making authority. (2) Community Lead/Manager — manages daily operations, implements policy. (3) Staff Lead — supervises staff, handles complaints and appeals. Administrative roles: Administration, Senior Moderation, Moderation (manages tickets, AOP changes, daily support), and Support (entry-level, limited authority).', 30),
('Section 3 — Community Organization', '3.2', 'Chain of Command', 'All members must follow the established Chain of Command when seeking assistance or resolving concerns. Concerns involving a direct superior may be escalated to the next appropriate rank. Final authority rests with the highest-ranking active staff member. Failure to respect the CoC may result in delays or disciplinary action if done in bad faith.', 31),
('Section 3 — Community Organization', '3.3', 'Applications & Promotions', 'The Los Angeles Experience promotes a merit-based progression system. Applications are reviewed based on current staffing needs, applicant conduct, and community engagement. Promotions are earned through demonstrated merit, professionalism, consistent activity, and adherence to community standards.', 32),

-- Section 4: Roleplay Regulations
('Section 4 — Roleplay Regulations', '4.1', 'General Roleplay Standards', 'Roleplay must reflect real-world logic and behavior. Conduct violations: FailRP (ignoring injuries, surviving fatal damage), Metagaming (using outside info in-character), Powergaming (forcing outcomes without giving others time to respond), RDM/VDM (killing or ramming without RP reason), GTA-Style Driving (ramp launches, hyper-speed cornering without RP justification), NITRP (no intent to participate in meaningful RP), Combat Logging (disconnecting mid-scene), RevengeRP (returning after death to retaliate), Baiting (intentionally provoking without IC justification), Abuse of Audio (soundboard disruption).', 40),
('Section 4 — Roleplay Regulations', '4.2', 'Character Rules', 'New Life Rule (NLR): When a character dies, they lose all memory of events leading up to that death. You may not return to the scene, seek revenge, or use information from the previous life situation. Characters must have realistic backgrounds, goals, and behaviors. Major shifts (e.g., cop becoming criminal) must be supported by in-character development. Throwaway characters used for repeated troll behavior will be subject to administrative review.', 41),
('Section 4 — Roleplay Regulations', '4.3', 'Area of Patrol (AOP)', 'All members must remain within the currently designated AOP during active RP unless otherwise directed by an active staff member. AOP is set by staff and may be adjusted based on server population or ongoing scenarios. Monitor in-game announcements or #server-status for AOP updates. Unauthorized relocation outside the AOP is not permitted.', 42),
('Section 4 — Roleplay Regulations', '4.4', 'Law Enforcement & Civilian Interaction', 'Civilians must comply with reasonable commands from law enforcement unless they have a valid in-character reason not to. Randomly fleeing or resisting without RP justification is FailRP. Civilians may not impersonate law enforcement, fire, EMS, or other emergency services without explicit staff approval. The /911 system is for in-character reporting only — no joke/troll messages, no requesting staff, no checking LEO availability.', 43),
('Section 4 — Roleplay Regulations', '4.5', 'Prohibited Scenarios', 'PROHIBITED without exception: Sexual Roleplay (any form), School Shootings or Education-Based Violence, Impersonation of Government/Public Services without authorization. RESTRICTED (requires prior staff approval): Terrorism/Mass Casualty Events, Sensitive or Disturbing Themes (excessive gore, mental health crises, real-world tragedies, hostage executions). Engaging in restricted scenarios without approval results in immediate administrative action.', 44),
('Section 4 — Roleplay Regulations', '4.6', 'Scene Management', 'Leaving a scene without in-character resolution to avoid arrest, death, or consequences is Combat Logging and is strictly prohibited. If you must leave due to technical issues or real-life emergency, notify involved parties via Discord or in-game OOC immediately. If you crash during an active scene, return as soon as possible and continue the RP. Deliberate disruption or evasion of scenes results in administrative action.', 45),
('Section 4 — Roleplay Regulations', '4.7', 'Priority Rules', 'A priority is any major in-character event requiring focused law enforcement response. Use /prio-start before initiating. Staff approval may be required. Back-to-back priorities from the same player/group are not permitted. After a priority, use /prio-stop — a cooldown follows during which no new priority may begin. Only join an active priority if invited by the initiating player. Every priority must have a realistic backstory. Priorities created purely for chaos or trolling will be shut down.', 46),
('Section 4 — Roleplay Regulations', '4.7a', 'Water Evasion Guidelines', 'Characters may remain underwater for no more than 10–20 seconds unless a valid in-character justification exists (e.g., scuba gear, specialized training). Water evasions should not exceed 5–10 minutes unless the character has a valid backstory. Circling endlessly, exploiting terrain, or avoiding capture through unrealistic movement will result in staff intervention.', 47),
('Section 4 — Roleplay Regulations', '4.8', 'Booster Vehicle Policy', 'Booster vehicles are provided for limited trial use and are monitored by staff at all times. All driving must remain realistic and immersion-friendly. Reckless or stunt-based driving is prohibited. Booster vehicles may not be used in priority situations or high-stakes law enforcement interactions. Misuse results in permanent loss of access with no opportunity for appeal.', 48),
('Section 4 — Roleplay Regulations', '4.9', 'In-Game Chats', '/ooc is for brief, necessary communication: quick clarifications, location/sound checks, confirming server performance issues. Prohibited: rude or inflammatory remarks, disrespect toward staff, using /ooc to argue or instigate drama, spamming. /gme (Global Me) is for scene-wide civilian updates or specialty unit availability — not casual OOC discussion. /me (Local Me) is for describing physical actions — not advertising, off-topic commentary, or powergaming.', 49),
('Section 4 — Roleplay Regulations', '4.10', 'Law Enforcement Standards', 'LEOs must maintain professionalism, realism, and integrity. Corruption is prohibited unless approved as an authorized RP storyline. Resources must match incident scale (e.g., noise complaint = 1–2 units, armed robbery = multiple units). Code 3 must reflect a real emergency. Radio must be clear and concise — mandatory call-ins: en route, on scene, and clear. When a dispatcher is online in CAD, do not self-attach to calls. Always clear your call once a scene concludes.', 50),
('Section 4 — Roleplay Regulations', '4.11', 'Civilian Roleplay Standards', 'All civilian characters must have a realistic name, consistent behavior, and believable backstory. Certain passive roles (Postal Worker, Taxi Driver, Waste Management, Airline Pilot, Construction Worker, Utility/City Maintenance) are restricted to neutral, non-criminal gameplay. Criminal RP must be justified, proportionate, and RP-driven — repetitive shootouts or mass violence with no setup are not permitted. All civilians must have characters, vehicles, and registered firearms in the CAD system before active RP.', 51),

-- Section 5: Media Policy
('Section 5 — Media Policy', '5.1', 'General Media Guidelines', 'All members are permitted to create and share media, but must do so consistent with community values. Members must not record, stream, or publish content involving private/administrative discussions or sensitive topics without prior permission. Media should reflect fair, respectful, and immersive gameplay.', 60),
('Section 5 — Media Policy', '5.2', 'Media Content Guidelines', 'Media should center around in-game scenarios and gameplay highlights. Do not include administrative tools, staff panels, internal discussions, or confidential material without written approval from a Community Manager or higher. Content must not include inappropriate language, harassment, bullying, gratuitous non-RP violence, or misleading titles/thumbnails that misrepresent the community. Avoid recording in areas designated for administrative tasks unless you have explicit approval.', 61),

-- Section 6: Disciplinary Procedures
('Section 6 — Disciplinary Procedures', '6.1', 'Types of Disciplinary Actions', 'Disciplinary actions: (1) Verbal Warning — non-formal reminder for minor/first-time offenses. (2) Written Warning — documented warning for moderate infractions. (3) Temporary Ban — suspension from server, typically hours to several days. (4) Permanent Ban — reserved for extreme or repeated violations; only issued after a player demonstrates unwillingness to follow rules. ESCALATION RULE: 3 bans of any kind = permanent ban. 4 warnings of any kind = permanent ban.', 70),
('Section 6 — Disciplinary Procedures', '6.2', 'Investigation Procedures', 'Report violations via the designated #tickets channel. A staff member reviews the report and escalates if sufficient cause exists. Evidence collected includes chat logs, recordings, and witness statements. The member involved is given an opportunity to share their side — honesty and cooperation are expected. All investigations are handled confidentially.', 71),
('Section 6 — Disciplinary Procedures', '6.3', 'Appeals Process', 'Appeals must be submitted within 7 days of the disciplinary action. Include all relevant evidence or reasoning. A staff member not involved in the original investigation will re-review the case. The administration team will issue a final decision: uphold, modify, or overturn. All appeal decisions are final.', 72),
('Section 6 — Disciplinary Procedures', '6.4', 'Code of Conduct During Investigations', 'Members must be truthful at all times during investigations. Members not directly involved must not attempt to influence, obstruct, or interfere. Investigations are conducted respectfully — comply with staff instructions and participate constructively.', 73),
('Section 6 — Disciplinary Procedures', '6.5', 'Non-Retaliation Policy', 'Retaliation against any member who submits a good-faith report, cooperates with an investigation, or serves as a witness is strictly prohibited. Retaliation includes threats, harassment, social exclusion, or retaliatory reporting. Counter-reports filed more than 48 hours after the original report, or reports with fabricated violations, will be treated as retaliation.', 74);


-- Indexes for performance
CREATE INDEX idx_threads_category ON threads(category_id);
CREATE INDEX idx_threads_user ON threads(user_id);
CREATE INDEX idx_posts_thread ON posts(thread_id);
CREATE INDEX idx_posts_user ON posts(user_id);
CREATE INDEX idx_notifications_user ON notifications(user_id, is_read);
CREATE INDEX idx_messages_receiver ON messages(receiver_id, is_read);

-- ============================================
-- MIGRATION: Add thread_type columns if upgrading existing install
-- Run these ALTER statements on existing databases:
-- ALTER TABLE threads ADD COLUMN thread_type ENUM('thread','application','appeal') DEFAULT 'thread' AFTER user_id;
-- ALTER TABLE threads ADD COLUMN application_id INT DEFAULT NULL AFTER thread_type;
-- ALTER TABLE threads ADD COLUMN appeal_id INT DEFAULT NULL AFTER application_id;
-- ============================================

-- ============================================
-- Per-Category Role Permissions
-- ============================================
-- Stores which roles can post/create threads in each category.
-- If NO rows exist for a category, the global role permissions apply.
-- If rows DO exist, ONLY those roles have that permission in that category.
CREATE TABLE category_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    role_id INT NOT NULL,
    can_post TINYINT(1) DEFAULT 1,
    can_create_threads TINYINT(1) DEFAULT 1,
    UNIQUE KEY unique_cat_role (category_id, role_id),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

-- Migration note for existing installs (also in migrate-v1.2.sql):
-- CREATE TABLE category_permissions (
--     id INT AUTO_INCREMENT PRIMARY KEY, category_id INT NOT NULL, role_id INT NOT NULL,
--     can_post TINYINT(1) DEFAULT 1, can_create_threads TINYINT(1) DEFAULT 1,
--     UNIQUE KEY unique_cat_role (category_id, role_id),
--     FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
--     FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
-- );

-- OAuth state table (for Discord and future OAuth providers)
-- Stores short-lived state tokens in DB as backup to session (avoids SameSite cookie issues)
CREATE TABLE oauth_states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state VARCHAR(64) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    provider VARCHAR(20) DEFAULT 'discord',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_state (state),
    INDEX idx_created (created_at)
);

-- ============================================================
-- MODERATION SYSTEM v1.0
-- Infractions: bans, kicks, warns, notes
-- Auto-generated IDs: LAE-XXXX format
-- ============================================================

CREATE TABLE IF NOT EXISTS infractions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    infraction_id VARCHAR(12) NOT NULL UNIQUE,   -- e.g. LAE-0001
    type         ENUM('ban','kick','warn','note') NOT NULL,
    player_name  VARCHAR(100) NOT NULL,
    player_identifier VARCHAR(100),              -- Steam hex / Discord ID / IP
    player_discord_id VARCHAR(30) DEFAULT NULL,
    reason       TEXT NOT NULL,
    duration     VARCHAR(50) DEFAULT NULL,        -- NULL = perm, '7d', '24h', etc.
    expires_at   DATETIME DEFAULT NULL,
    issued_by_id INT DEFAULT NULL,               -- forum user who issued it
    issued_by_name VARCHAR(100) NOT NULL,         -- cached name at time of issue
    issued_via   ENUM('website','discord') DEFAULT 'website',
    is_active    TINYINT(1) DEFAULT 1,
    removed_by   VARCHAR(100) DEFAULT NULL,
    removed_at   DATETIME DEFAULT NULL,
    remove_reason TEXT DEFAULT NULL,
    notes        TEXT DEFAULT NULL,              -- internal staff notes
    discord_msg_id VARCHAR(30) DEFAULT NULL,     -- message ID in log channel
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (issued_by_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_player_name  (player_name),
    INDEX idx_identifier   (player_identifier),
    INDEX idx_type         (type),
    INDEX idx_active       (is_active),
    INDEX idx_infraction_id (infraction_id)
);

-- Auto-increment infraction ID sequence
CREATE TABLE IF NOT EXISTS infraction_seq (
    id INT NOT NULL DEFAULT 0
);
INSERT IGNORE INTO infraction_seq VALUES (0);

-- ============================================================
-- Migration v1.5 — Remember Me, Multi-Roles, Banner, View dedup
-- ============================================================

-- Remember-me tokens table
CREATE TABLE IF NOT EXISTS remember_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token_hash),
    INDEX idx_user  (user_id)
);

-- Thread view tracking (dedup by IP+session per thread)
CREATE TABLE IF NOT EXISTS thread_views (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    thread_id  INT NOT NULL,
    viewer_key VARCHAR(64) NOT NULL,   -- hash(ip + session_id + thread_id)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_view (thread_id, viewer_key),
    FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE
);

-- Extra user columns
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS banner VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS extra_roles TEXT DEFAULT NULL;  -- JSON array of extra role IDs
