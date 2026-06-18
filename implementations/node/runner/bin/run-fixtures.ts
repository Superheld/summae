import { loadFixtures } from '../src/fixture-loader.js';
import { SuiteRunner } from '../src/suite-runner.js';
import { CoreSubjectFactory } from '../src/subject/core-subject-factory.js';

const args = process.argv.slice(2);
let filter: string | undefined;
let strict = false;
for (const arg of args) {
  if (arg.startsWith('--filter=')) filter = arg.slice('--filter='.length);
  else if (arg === '--strict') strict = true;
}

const fixtures = loadFixtures();
const suite = new SuiteRunner(new CoreSubjectFactory()).run(fixtures, filter);

let green = 0;
let red = 0;
let crash = 0;
for (const result of suite.results) {
  if (result.status === 'pass') {
    green++;
    console.log(`PASS  ${result.fixture}`);
  } else if (result.status === 'crash') {
    crash++;
    console.log(`CRASH ${result.fixture}: ${result.crashReason ?? ''}`);
  } else {
    red++;
    console.log(`FAIL  ${result.fixture}`);
    for (const diff of result.diffs) console.log(`      ${diff}`);
  }
}

console.log(`\n${suite.results.length} Fixtures: ${green} grün, ${red} rot, ${crash} Crashes`);
console.log(
  suite.determinismBreaks.length > 0
    ? `Determinismus-Bruch: ${suite.determinismBreaks.join(', ')}`
    : 'Doppellauf deterministisch.',
);

const ok = red === 0 && crash === 0 && (!strict || suite.determinismBreaks.length === 0);
process.exit(ok ? 0 : 1);
