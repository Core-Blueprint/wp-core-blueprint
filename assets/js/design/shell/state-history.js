/** Public transient history for JSON-safe domain states. */
const copy = value => JSON.parse(JSON.stringify(value));
export const createDesignerStateHistory = ({initialState, onApply, limit = 100} = {}) => {
  if (typeof onApply !== 'function') throw new TypeError('onApply required');
  if (!Number.isInteger(limit) || limit < 1 || limit > 1000) throw new RangeError('Invalid history limit');
  const state = value => {
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new TypeError('State must be a plain object');
    return copy(value);
  };
  let stack = [state(initialState)], index = 0, disposed = false;
  const check = () => { if (disposed) throw new Error('History disposed'); };
  const status = () => { check(); return Object.freeze({canUndo: index > 0, canRedo: index < stack.length-1, size: stack.length-1}); };
  const record = value => {
    check();
    const next = state(value);
    if (JSON.stringify(next) === JSON.stringify(stack[index])) return false;
    stack = stack.slice(0,index+1);
    stack.push(next);
    if (stack.length > limit + 1) stack.shift();
    index = stack.length-1;
    return true;
  };
  const apply = target => {
    check();
    if (target < 0 || target >= stack.length) return false;
    onApply(copy(stack[target]));
    index = target;
    return true;
  };
  return Object.freeze({
    record,
    undo: () => apply(index-1),
    redo: () => apply(index+1),
    reset(base,{current=null}={}) { check(); stack=[state(base)];index=0;if(current!==null)record(current);return status(); },
    snapshot() {check();return copy(stack[index]);},
    status,
    dispose(){disposed=true;stack=[];index=0;},
  });
};
