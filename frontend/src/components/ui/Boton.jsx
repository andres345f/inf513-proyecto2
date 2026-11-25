import './Boton.css'

/**
 * Botón del sistema. variante: primario | secundario | fantasma | peligro.
 * Con `como={Link}` (y `to=…`) se renderiza como enlace con aspecto de botón.
 */
export default function Boton({
  children, variante = 'primario', tipo = 'button', cargando = false, bloque = false, className = '',
  como: Componente = 'button', disabled = false, ...resto
}) {
  const clases = ['boton', `boton--${variante}`, bloque && 'boton--bloque', className].filter(Boolean).join(' ')
  const esBoton = Componente === 'button'

  return (
    <Componente
      {...(esBoton ? { type: tipo, disabled: cargando || disabled } : {})}
      className={clases}
      aria-busy={cargando || undefined}
      {...resto}
    >
      {cargando && <span className="boton__indicador" aria-hidden="true" />}
      <span>{children}</span>
    </Componente>
  )
}
