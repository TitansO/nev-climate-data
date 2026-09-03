/**
 * Shared text/date/number input with a real <label htmlFor> and
 * aria-invalid/aria-describedby wiring - closes a real accessibility gap
 * (DataPage's 6 filters and the login/profile forms had none of this).
 */
export default function NevInput({ id, label, error, hint, required = false, wrapperClassName = "", className = "", ...rest }) {
  const describedBy = error ? id + "-error" : hint ? id + "-hint" : undefined;

  return (
    <div className={wrapperClassName}>
      {label ? (
        <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-dark">
          {label}
          {required ? (
            <span className="text-danger" aria-hidden="true">
              {" *"}
            </span>
          ) : null}
        </label>
      ) : null}
      <input
        id={id}
        required={required}
        aria-invalid={!!error || undefined}
        aria-describedby={describedBy}
        className={
          "w-full rounded-md border border-stroke bg-white px-3 py-2.5 text-sm text-dark focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary " +
          className
        }
        {...rest}
      />
      {error ? (
        <p id={id + "-error"} className="mt-1.5 text-xs text-danger">
          {error}
        </p>
      ) : hint ? (
        <p id={id + "-hint"} className="mt-1.5 text-xs text-body-color">
          {hint}
        </p>
      ) : null}
    </div>
  );
}
