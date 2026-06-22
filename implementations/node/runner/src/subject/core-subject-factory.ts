import type { Subject, SubjectFactory } from '../subject.js';
import { CoreSubject } from './core-subject.js';

/** Provides a fresh in-memory subject per run (determinism double run). */
export class CoreSubjectFactory implements SubjectFactory {
  create(): Subject {
    return new CoreSubject();
  }
}
