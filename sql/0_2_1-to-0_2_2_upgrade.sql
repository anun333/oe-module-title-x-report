-- Title X Report 0.2.1 to 0.2.2: an index on core's form_questionnaire_assessments.response_id.
-- The questionnaire queries join on that column, which core leaves without an index (a TEXT column), so on
-- a clinic's years of data each screening lookup scanned every assessment form. The UDS Report, Title X
-- Report and CCBHC Report modules add the same index under the same name, so whichever runs first creates it
-- and the others skip it; if core adds one named response_id, this is skipped too. The same block is in
-- install.sql.
#IfNotIndex form_questionnaire_assessments response_id
ALTER TABLE `form_questionnaire_assessments` ADD INDEX `response_id` (`response_id`(64));
#EndIf
