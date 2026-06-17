import type { Subject, SubjectFactory } from '../subject.js';
import { CoreSubject } from './core-subject.js';

/** Liefert je Lauf ein frisches In-Memory-Subject (Determinismus-Doppellauf). */
export class CoreSubjectFactory implements SubjectFactory {
  create(): Subject {
    return new CoreSubject();
  }
}
