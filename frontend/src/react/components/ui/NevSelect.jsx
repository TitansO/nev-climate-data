/**
 * Shared <select>, same label/error/hint contract as NevInput. Consumer
 * passes real <option> children (e.g. DataPage's dynamically-learned
 * sector list) - this component never invents options.
 */
export default function NevSelect({ id, label, error, hint, required = false, wrapperClassName = "", className = "", children, ...rest }) {
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
      <select
        id={id}
        required={required}
        aria-invalid={!!error || undefined}
        aria-describedby={describedBy}
        className={
          "w-full rounded-md border border-stroke bg-white px-3 py-2.5 text-sm text-dark focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary " +
          className
        }
        {...rest}
      >
        {children}
      </select>
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
